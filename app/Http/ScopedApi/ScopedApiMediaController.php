<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use LogicException;
use OpenApi\Attributes as OA;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyFileIngest;
use Spora\Services\ApiKeys\ApiKeyMediaAccess;
use Spora\Services\ApiKeys\ApiKeyReach;
use Spora\Services\AssetStreamer;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * File input and output for the scoped API surfaces.
 *
 * An agent that can see a picture should be able to send one, and a caller
 * that reads the answer should be able to fetch the images in it. Both are
 * limited to the key's reach, and both answer 404 rather than 403 for an
 * asset outside it — a key must not be able to probe which media ids exist.
 *
 * One implementation serves both the group surface and the global surface:
 * the OpenAPI tag is derived from the path, so each surface gets its own
 * page.
 */
final class ScopedApiMediaController
{
    use JsonControllerHelpers;

    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';

    private const MSG_NO_OWNER = 'The agent has no resolvable owner.';

    public function __construct(
        private readonly ApiKeyFileIngest $ingest,
        private readonly ApiKeyMediaAccess $media,
        private readonly ApiKeyReach $reach,
        private readonly AssetStreamer $streamer,
        private readonly MediaAssetSerializer $serializer,
    ) {}

    #[OA\Post(
        summary: 'Upload a file for one of the agents this key reaches',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'agent_id', type: 'integer'),
                    ],
                ),
            ),
        ),
    )]
    #[OA\Response(response: 201, description: 'The asset was ingested.')]
    #[OA\Response(response: 400, description: 'No file was sent under "file".')]
    #[OA\Response(response: 404, description: 'No such agent, or not one this key may reach.')]
    #[OA\Response(response: 415, description: 'The file type is not in the agent\'s allowlist.')]
    public function store(Request $request): JsonResponse
    {
        $bytes = $this->readUpload($request);

        if ($bytes instanceof JsonResponse) {
            return $bytes;
        }

        $outcome = $this->ingest($request, $bytes);

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        return new JsonResponse(
            ['data' => $this->serializer->serialize($outcome, null)],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Get(
        summary: 'Fetch a media asset this key may read',
        parameters: [new OA\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 200, description: 'The asset bytes.')]
    #[OA\Response(response: 404, description: 'No such asset, or not one this key may read.')]
    public function show(Request $request, string $mediaId): Response
    {
        $asset = $this->media->findMedia(ApiKeyContext::fromRequest($request), $mediaId);

        if ($asset === null) {
            return $this->notFound('NOT_FOUND', 'Media not found.');
        }

        // `inline` rather than the admin route's `attachment`: an agent's
        // output is usually consumed by an LLM or shown to a person, not
        // saved to disk by a browser.
        return $this->streamer->stream($asset, 'inline') ?? $this->notFound('NOT_FOUND', 'Media not found.');
    }

    /**
     * The agent the upload is for, and the user it is attributed to — or
     * the answer saying why there is none.
     *
     * @return array{agent: Agent, ownerUserId: int}|JsonResponse
     */
    private function target(Request $request): array|JsonResponse
    {
        $agentId = $this->intField($request->request->all(), 'agent_id');

        if ($agentId === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'agent_id is required.');
        }

        $agent = $this->reach->targetAgent(ApiKeyContext::fromRequest($request), $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_AGENT_NOT_FOUND);
        }

        $ownerUserId = $this->reach->ownerUserId($agent);

        return $ownerUserId === null
            ? $this->unprocessable('VALIDATION_ERROR', self::MSG_NO_OWNER)
            : ['agent' => $agent, 'ownerUserId' => $ownerUserId];
    }

    /**
     * Ingest the upload, or say why it cannot be ingested.
     *
     * @param array{bytes: string, name: string} $bytes
     * @return JsonResponse|MediaAsset
     */
    private function ingest(Request $request, array $bytes): JsonResponse|MediaAsset
    {
        $target = $this->target($request);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $mediaId = $this->ingest->ingestBytes(
            $target['agent'],
            $target['ownerUserId'],
            $bytes['bytes'],
            $bytes['name'],
        );

        if ($mediaId === null) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                'This file type is not accepted by the agent.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $asset = $this->media->findMedia(ApiKeyContext::fromRequest($request), $mediaId);

        if ($asset === null) {
            // The ingest stamped the agent id, so an id that cannot be read
            // back means the write and the scope predicate disagree — a bug
            // worth failing loudly on rather than answering with a half-result.
            throw new LogicException('Ingested media is immediately out of scope.');
        }

        return $asset;
    }

    /**
     * The client's filename is attacker-influenced and ends up in a
     * `Content-Disposition` header on the read path, so it is reduced to its
     * basename before it reaches storage.
     */
    private function safeName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());

        return $name === '' ? 'upload' : $name;
    }

    /**
     * @return JsonResponse|array{bytes: string, name: string}
     */
    private function readUpload(Request $request): JsonResponse|array
    {
        $file = $request->files->get('file');

        $failure = match (true) {
            !$file instanceof UploadedFile
                => $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST),
            !$file->isValid()
                => $this->error('BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage(), Response::HTTP_BAD_REQUEST),
            default => null,
        };

        if ($failure !== null) {
            return $failure;
        }

        $bytes = file_get_contents($file->getPathname());

        return $bytes === false
            ? $this->error('READ_FAILED', 'Could not read uploaded file.', Response::HTTP_INTERNAL_SERVER_ERROR)
            : ['bytes' => $bytes, 'name' => $this->safeName($file)];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intField(array $params, string $name): ?int
    {
        $value = $params[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
