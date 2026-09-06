<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Psr\Container\ContainerInterface;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Generic surface for producing a derivative of a media asset.
 *
 * POST /api/v1/media/{id}/derivatives
 * body: { "format": "pdf", "options": { "dpi": 300 } }
 *
 * Walks {@see MediaDerivativeProducerDiscovery::all()} for a producer
 * that accepts the parent asset's MIME/extension AND supports the
 * requested format. The producer's `pluginSlug()` /
 * `operationName()` are recorded on the `media_derivatives` row for
 * attribution; the natural key
 * `(parent_id, format, producer_plugin, producer_operation)` makes
 * re-rendering idempotent — the same derivative id comes back.
 *
 * Status contract:
 *   - 201 Created             — new derivative persisted (or refresh on existing)
 *   - 404 Not Found           — parent missing or not visible to the caller
 *   - 409 Conflict            — no producer supports the format/source pair
 *   - 422 Unprocessable Entity — the producer threw during `produce()`
 */
final class MediaDerivativeController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly MediaDerivativeService $derivatives,
        private readonly AuthService $auth,
        private readonly ContainerInterface $container,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
    ) {}

    public function create(string $id, Request $request): JsonResponse
    {
        $parent = MediaAsset::query()->find($id);
        if ($parent === null || !$this->canSee($parent)) {
            return $this->notFound('NOT_FOUND', 'Media asset not found.');
        }

        $payload = $this->decodeJsonBody($request);
        $format  = (string) ($payload['format'] ?? '');
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        [$status, $body] = $this->produceDerivativeResponse($parent, $format, $options);

        return new JsonResponse($body, $status);
    }

    /**
     * Run the produce pipeline and return [status, body] for the HTTP
     * response. Pulled out of `create()` so the controller entry point
     * has a single trailing `return new JsonResponse(...)`.
     *
     * @param array<string, mixed> $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function produceDerivativeResponse(MediaAsset $parent, string $format, array $options): array
    {
        $producer = $format === '' ? null : $this->findProducer($parent, $format);
        if ($format === '' || $producer === null) {
            return $this->rejection(
                $format === '' ? Response::HTTP_BAD_REQUEST : Response::HTTP_CONFLICT,
                $format === '' ? 'BAD_REQUEST' : 'NO_PRODUCER',
                $format === '' ? '`format` is required.' : 'No producer supports this format for this asset.',
            );
        }

        try {
            $output = $producer->produce($parent, $format, $options);
            $derivative = $this->derivatives->create(
                parent: $parent,
                output: $output,
                format: $format,
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: $this->auth->currentUserId(),
                context: $this->resolveContext(),
            );
            return [Response::HTTP_CREATED, [
                'data' => ['derivative' => $this->serializer->serialize($derivative)],
            ]];
        } catch (Throwable $e) {
            return $this->rejection(Response::HTTP_UNPROCESSABLE_ENTITY, 'PRODUCER_FAILED', $e->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rejection(int $status, string $code, string $message): array
    {
        return [$status, ['error' => ['code' => $code, 'message' => $message]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function findProducer(MediaAsset $parent, string $format): ?MediaDerivativeProducerInterface
    {
        $format = strtolower($format);
        $mime   = strtolower((string) $parent->mime_type);
        $ext    = strtolower(pathinfo((string) $parent->filename, PATHINFO_EXTENSION));
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            /** @var MediaDerivativeProducerInterface $producer */
            $producer = $this->container->get($class);
            $sources = array_map('strtolower', $producer->supportedSourceFormats());
            $outputs = array_map('strtolower', $producer->supportedDerivativeFormats());
            if (
                in_array($format, $outputs, true)
                && (
                    ($mime !== '' && in_array($mime, $sources, true))
                    || ($ext !== '' && in_array($ext, $sources, true))
                )
            ) {
                return $producer;
            }
        }
        return null;
    }

    private function canSee(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }
        $userId = $this->auth->currentUserId();
        return $userId !== null && $asset->user_id !== null && (int) $asset->user_id === $userId;
    }

    /**
     * Build a {@see PrincipalContext} for the caller. The controller path
     * doesn't have an agent id (the producer is plugin-driven, not agent-
     * driven), so we resolve the user's user-principal only. The result
     * lets {@see MediaDerivativeService::create()} inherit `principal_id`
     * when the parent has none — the same precedence chain the ingest
     * pipeline uses, so LIST and CREATE agree on the row's principal.
     */
    private function resolveContext(): ?PrincipalContext
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return null;
        }
        $principal = Principal::query()
            ->where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->first();
        if ($principal === null) {
            return null;
        }
        return new PrincipalContext(
            principalId: (int) $principal->id,
            type: (string) $principal->type,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );
    }
}
