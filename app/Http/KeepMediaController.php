<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/media/{id}/keep` — promote a temp row to permanent so
 * the per-(user, agent) retention sweep leaves it alone.
 *
 * Auth gate mirrors {@see \Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController::canEdit()}:
 *   - global admin → yes;
 *   - asset owner (`user_id == currentUserId`) → yes;
 *   - everyone else → 403 (existence-hiding for callers who probe for
 *     a row id they don't own; the lookup itself uses
 *     {@see MediaArchiveService::find()} so a missing row returns 404
 *     before the gate fires).
 *
 * The update is idempotent: flipping a non-temp row is a no-op that
 * still returns 200 with the serialised payload so the dashboard can
 * re-fetch without branchy client code.
 *
 * Body: empty. Any `application/json` body is ignored — the wire
 * contract is "this id, this caller, no payload".
 */
final class KeepMediaController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
    ) {}

    public function keep(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', 'Media asset not found.');
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden('FORBIDDEN', 'You do not own this media asset.');
        }

        if ((bool) $asset->is_temporary) {
            $asset->is_temporary = false;
            $asset->save();
        }

        return new JsonResponse(
            ['data' => $this->serializer->serialize($asset)],
            Response::HTTP_OK,
        );
    }

    /**
     * Mirrors the gate in
     * {@see \Spora\Plugins\MediaArchive\Http\MediaArchiveAdminController::canEdit()}.
     * Duplicated (rather than promoted to a shared trait) because the
     * plugin lives in a separate repo — the helper is short enough that
     * keeping the rule in one file is worth the 4-line dup.
     */
    private function canEdit(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }
        $userId = $this->auth->currentUserId();
        return $userId !== null && $asset->user_id !== null && (int) $asset->user_id === $userId;
    }
}
