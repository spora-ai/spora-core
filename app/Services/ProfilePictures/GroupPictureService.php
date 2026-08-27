<?php

declare(strict_types=1);

namespace Spora\Services\ProfilePictures;

use Spora\Models\GroupPicture;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;
use Spora\Services\Exceptions\AgentPictureNotOwnedException;

/**
 * Picture editor for Groups — owns the CRUD surface and the wire-format
 * contract for `group_pictures`. Mirrors
 * {@see \Spora\Services\AgentPictures\AgentPictureService}; the shared
 * validation, hashing, and wire-shape logic lives in
 * {@see ProfilePictureService} so both subjects stay in sync.
 *
 * Differences from the agent variant:
 *   - The owning principal is the user uploading the asset (groups don't
 *     have a `user_id` of their own), so the asset-ownership check
 *     compares against the caller's user id directly.
 *   - Default archetype is `Collaborative` rather than `Assistant` so
 *     a freshly-created group renders with a group-flavored icon.
 */
final class GroupPictureService extends ProfilePictureService
{
    public const DEFAULT_ARCHETYPE = Archetype::Collaborative;
    public const DEFAULT_PALETTE = Palette::Slate;

    /**
     * Bind an uploaded image as the group's picture. The asset must
     * already be ingested via MediaArchiveService::ingest() — this
     * method only sets the FK on the group_pictures row.
     *
     * Ownership invariant: the asset's `user_id` must match the
     * `$callerUserId`. Mismatches (and assets with a NULL user_id) are
     * rejected with {@see AgentPictureNotOwnedException} — the HTTP
     * path is already user-scoped, but the service-level guard prevents
     * future internal callers from attaching a cross-user asset.
     *
     * The avatar selection (archetype + variant + palette) is preserved
     * so removing the uploaded image reverts to the operator's previous
     * avatar choice rather than the hard-coded defaults.
     *
     * @throws AgentPictureNotOwnedException when the asset's user does not match.
     */
    public function attachImage(int $groupId, MediaAsset $asset, int $callerUserId): GroupPicture
    {
        $this->assertAssetOwnership($asset, $callerUserId);

        $picture = $this->getOrCreate($groupId);

        \Illuminate\Database\Capsule\Manager::table('group_pictures')
            ->where('id', $picture->id)
            ->update([
                'media_asset_id' => $asset->id,
                'updated_at'     => date(self::DATETIME_FORMAT),
                // archetype + variant + palette are intentionally untouched
                // so a follow-up detach restores the operator's previous
                // avatar selection instead of the hard-coded defaults.
            ]);
        $picture->refresh();
        return $picture;
    }

    /**
     * Verify that `asset->user_id` matches `$callerUserId`. Reject assets
     * owned by a different user or by no user at all (NULL). The HTTP
     * layer guards this already, but the service contract is the
     * authoritative one — future internal callers (e.g. group-settings
     * image picking) might not have that guarantee.
     */
    private function assertAssetOwnership(MediaAsset $asset, int $callerUserId): void
    {
        if ($asset->user_id === null || (int) $asset->user_id !== $callerUserId) {
            throw AgentPictureNotOwnedException::forAsset($callerUserId, $asset->id, 'user');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function avatarWireShape(mixed $picture): array
    {
        $archetype = $picture->archetype !== null
            ? Archetype::tryFrom($picture->archetype) ?? self::DEFAULT_ARCHETYPE
            : self::DEFAULT_ARCHETYPE;
        $palette = $picture->palette_key !== null
            ? Palette::tryFrom($picture->palette_key) ?? self::DEFAULT_PALETTE
            : self::DEFAULT_PALETTE;
        $variantKey = $picture->variant_key ?? $this->resolveVariantKey((int) $picture->group_id);

        return [
            'kind'             => 'avatar',
            'archetype'        => $archetype->value,
            'variant_key'      => $variantKey,
            'palette_key'      => $palette->value,
            'fg_color'         => $palette->foreground(),
            'bg_color'         => $palette->background(),
            'image_url'        => null,
            'image_updated_at' => null,
        ];
    }

    protected function defaultArchetype(): Archetype
    {
        return self::DEFAULT_ARCHETYPE;
    }

    protected function defaultPalette(): Palette
    {
        return self::DEFAULT_PALETTE;
    }

    /**
     * @return class-string<GroupPicture>
     */
    protected function pictureModel(): string
    {
        return GroupPicture::class;
    }

    protected function pictureTable(): string
    {
        return 'group_pictures';
    }

    protected function subjectKey(): string
    {
        return 'group_id';
    }
}
