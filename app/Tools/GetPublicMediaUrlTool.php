<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tool that hands a public URL for a media file to the agent.
 *
 * Two operations:
 *
 *   - `get`  — return the public URL of a media file the calling user
 *              owns. Errors if `public_access_token` is not set (the
 *              user hasn't enabled sharing). Does NOT require
 *              approval.
 *
 *   - `share` — enable public sharing on a media file (mints a
 *               `public_access_token`). Requires approval. If the
 *               token is already set, leaves it alone — use
 *               `POST /api/v1/media/{id}/public-token/refresh` to
 *               rotate.
 *
 * Useful for handing media to a third-party API (social-media
 * poster, image host) that requires a publicly-fetchable URL.
 */
#[Tool(
    name: 'get_public_media_url',
    description: 'Get or enable the public URL of a media file. Public URLs are useful for handing media to third-party APIs that require a publicly-fetchable URL (e.g. social-media posters).',
    displayName: 'Public Media URL',
    category: 'media',
    icon: 'image',
)]
#[ToolOperation(name: 'get', description: 'Get the public URL of a media file. Errors if sharing is not enabled.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'share', description: 'Enable public sharing on a media file (mints a public_access_token). Requires user approval.', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolParameter(
    name: 'media_id',
    type: 'string',
    description: 'The UUID of the media asset.',
    required: true,
)]
#[ToolParameter(
    name: 'action',
    type: 'string',
    description: 'Either "get" (return the URL) or "share" (enable sharing).',
    required: false,
)]
final class GetPublicMediaUrlTool extends AbstractTool
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ?Request $request = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $parsed = $this->parseArguments($arguments);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }

        [$mediaId, $action] = $parsed;
        $asset = $this->loadOwnedAsset($mediaId, $userId);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        return $this->resultForAction($asset, $action);
    }

    /** @return array{0: string, 1: string}|ToolResult */
    private function parseArguments(array $arguments): array|ToolResult
    {
        $mediaId = trim((string) ($arguments['media_id'] ?? ''));
        if ($mediaId === '') {
            return ToolResult::fail('media_id is required.');
        }
        $action = (string) ($arguments['action'] ?? 'get');
        if (!in_array($action, ['get', 'share'], true)) {
            return ToolResult::fail('action must be "get" or "share".');
        }

        return [$mediaId, $action];
    }

    /** @return MediaAsset|ToolResult */
    private function loadOwnedAsset(string $mediaId, ?int $userId): MediaAsset|ToolResult
    {
        $asset = MediaAsset::query()->find($mediaId);
        if ($asset === null) {
            return ToolResult::fail('Media asset not found.');
        }

        $effectiveUserId = $userId ?? $this->auth->currentUserId();
        $error = null;
        if ($effectiveUserId === null) {
            $error = ToolResult::fail('You must be logged in to use this tool.');
        } elseif (!$this->auth->isAdmin() && (int) $asset->user_id !== $effectiveUserId) {
            $error = ToolResult::fail('You do not own this media asset.');
        }

        return $error ?? $asset;
    }

    private function resultForAction(MediaAsset $asset, string $action): ToolResult
    {
        if ($action === 'share') {
            if ($asset->public_access_token === null || $asset->public_access_token === '') {
                $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
                $asset->save();
            }
            return ToolResult::ok('Public sharing enabled. URL: ' . $this->publicUrl($asset));
        }

        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            return ToolResult::fail('This media is not shared publicly. Ask the user to enable sharing in the Media Archive, or call this tool with action="share" (requires approval).');
        }

        return ToolResult::ok('Public URL: ' . $this->publicUrl($asset));
    }

    public function describeAction(array $arguments): string
    {
        $mediaId = (string) ($arguments['media_id'] ?? '');
        $action  = (string) ($arguments['action'] ?? 'get');
        return "Public media URL ({$action}) for {$mediaId}";
    }

    private function publicUrl(MediaAsset $asset): string
    {
        $host = $this->request !== null
            ? $this->request->getSchemeAndHttpHost()
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return rtrim($host, '/') . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }
}
