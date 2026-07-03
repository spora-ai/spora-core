<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\LocalAssetStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves files previously written by {@see LocalAssetStore}.
 *
 * Route: `GET /api/v1/assets/{filename}` — registered without middleware
 * in {@see \Spora\Core\RouteDefinitions}. Authorization is the URL itself:
 * {@see LocalAssetStore::resolve()} verifies the HMAC token before the
 * file is opened. Same-origin browser requests still attach session
 * cookies, but no authenticated user is required to play back media.
 *
 * Same pattern as S3 presigned URLs: knowledge of the URL = access.
 */
final class AssetController
{
    public function __construct(
        private readonly LocalAssetStore $store,
    ) {}

    public function show(string $filename): BinaryFileResponse|JsonResponse
    {
        $resolved = $this->store->resolve($filename);
        if ($resolved === null) {
            // Match the standard JSON error envelope used by every other
            // controller (see JsonControllerHelpers::notFound()). Asset
            // routes don't use the trait because they need to return
            // BinaryFileResponse on success, but the error shape stays
            // consistent so client error handling is uniform.
            return new JsonResponse(
                ['error' => ['code' => 'asset_not_found', 'message' => 'Asset not found.']],
                404,
            );
        }

        $response = new BinaryFileResponse(
            $resolved['path'],
            200,
            ['Content-Type' => $resolved['mime']],
        );
        // Asset URLs are stable for ~24 h; the per-day HMAC rotation
        // handles invalidation server-side without us touching headers.
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }
}
