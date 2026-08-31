<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic surface for enumerating the derivative formats a given
 * media asset can be converted into.
 *
 * GET /api/v1/media/{id}/derivatives/options
 *
 * Returns the union of every registered
 * {@see \Spora\Services\MediaArchive\MediaDerivativeProducerInterface}'s
 * `supportedDerivativeFormats()`, each with `available: true|false`
 * based on whether the producer's `supportedSourceFormats()` contains
 * the parent's MIME or extension. With no producers registered the
 * endpoint returns an empty array (not an error) so newly-installed
 * plugins can still call it safely.
 */
final class MediaDerivativeOptionsController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly MediaDerivativeService $derivatives,
        private readonly AuthService $auth,
    ) {}

    public function index(string $id): JsonResponse
    {
        $parent = MediaAsset::query()->find($id);
        if ($parent === null || !$this->canSee($parent)) {
            return $this->notFound('NOT_FOUND', 'Media asset not found.');
        }

        return new JsonResponse(
            ['data' => $this->derivatives->availableOptionsFor($parent)],
            Response::HTTP_OK,
        );
    }

    private function canSee(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }
        $userId = $this->auth->currentUserId();
        return $userId !== null && $asset->user_id !== null && (int) $asset->user_id === $userId;
    }
}
