<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Models\MediaAsset;
use Spora\Services\AssetStreamer;
use Spora\Services\MediaArchive\MediaActiveTypesPolicy;
use Spora\Services\MediaArchive\MediaAssetAccess;
use Spora\Services\MediaArchive\MediaScriptableTypes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a media asset as live, embeddable content.
 *
 * GET /api/v1/media/{id}/embed
 *
 * This is the one route that may serve script-capable bytes with scripting
 * *permitted*, and it is deliberately narrow on every axis:
 *
 *  - **Authenticated only.** The route reuses the asset route's ownership
 *    union, so a public share token does not unlock it. A script-enabled
 *    HTML page reachable by anyone with a link turns the operator's domain
 *    into a phishing and malware-distribution host — the operator's users
 *    would see the trusted domain in the URL bar.
 *  - **Only when the operator permitted the format.** With the active-types
 *    toggle off, the route 404s even for a row that exists, because the
 *    operator has withdrawn the class.
 *  - **Only for the script-capable types.** An ordinary asset has nothing
 *    to embed, and serving it here would give a second path to the bytes
 *    with weaker headers than the asset route's.
 *
 * Why a separate route rather than the plain asset URL: the plain URL must
 * stay inert, or the share link becomes the execution vector the serving
 * boundary exists to close. The embed is a different surface with a
 * different contract — a deliberate act by a privileged user.
 *
 * The isolation is three independent mechanisms, any one of which is
 * sufficient:
 *
 *  1. `sandbox` without `allow-same-origin` puts the framed document in an
 *     opaque origin. It cannot read `document.cookie`, cannot reach
 *     `localStorage`, and cannot touch the parent document.
 *  2. Spora sends no CORS headers, so a cross-origin request from the
 *     framed document has an unreadable response.
 *  3. Every write needs an `X-CSRF-Token`, which lives in the session and
 *     is readable only from the app's own origin. The session cookie is
 *     `SameSite=Lax`, so a cross-origin request does not carry it either.
 *
 * There is no bridge: the embedded document cannot `postMessage` the app to
 * trigger actions. A bridge would need a message contract and an action
 * allow-list, and would reintroduce exactly the coupling the sandbox
 * removes.
 */
final class MediaEmbedController
{
    public function __construct(
        private readonly AssetStreamer $streamer,
        private readonly MediaAssetAccess $access,
        private readonly MediaActiveTypesPolicy $activeTypes,
    ) {}

    #[OA\Get(
        path: '/api/v1/media/{id}/embed',
        summary: 'Fetch a media asset as live, embeddable content',
        tags: ['Media'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 200, description: 'The asset bytes, sandboxed. The CSP header carries the operator\'s script policy.')]
    #[OA\Response(response: 404, description: 'No such asset, not readable by the caller, or not a format the operator permitted.')]
    public function show(string $id): Response
    {
        $asset = MediaAsset::query()->find($id);
        if ($asset === null || !$this->access->canRead($asset)) {
            return $this->notFound();
        }

        $mime = (string) ($asset->mime_type ?? '');

        // The format must be one the operator permits *and* one that has
        // something to embed. Checking the mime here rather than trusting
        // the row's `media_type` column: the column is a coarse bucket, and
        // the mime is what the browser will parse.
        if (!MediaScriptableTypes::isScriptable($mime) || !$this->activeTypes->allowActiveTypes()) {
            return $this->notFound();
        }

        // The asset route's streamer, so the storage-mode branching stays in
        // one place — a second copy of it would be a second answer to
        // "which modes can be served". Its own headers are replaced below:
        // the embed carries a different policy from the plain asset URL.
        $response = $this->streamer->stream($asset, 'inline');
        if ($response === null) {
            return $this->notFound();
        }

        $response->headers->set(
            'Content-Security-Policy',
            MediaScriptableTypes::embedPolicy($this->activeTypes->allowEmbedScripts()),
        );
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Media asset not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
