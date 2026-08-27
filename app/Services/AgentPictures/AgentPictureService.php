<?php

declare(strict_types=1);

namespace Spora\Services\AgentPictures;

use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\MediaAsset;
use Spora\Services\Exceptions\AgentPictureNotOwnedException;
use Spora\Services\ProfilePictures\ProfilePictureService;

/**
 * Picture editor for Agents — owns the CRUD surface and the wire-format
 * contract. The HTTP layer (AgentController update + AgentPictureController
 * image routes) is a thin wrapper around this service.
 *
 * Invariants the service enforces:
 *   - `archetype` set XOR `media_asset_id` set. The two kinds of picture
 *     are mutually exclusive; a request that mixes both is rejected.
 *   - `archetype` and `palette_key` are validated against the enum.
 *   - `variant_key` is normalised:
 *       - null → auto-derive from `fnv1a(agent_id) % 3` for stable
 *         uniqueness across devices/reloads.
 *       - 'vN' with N ∈ [0..2] → keep as-is (operator override).
 *   - `media_asset_id` references a row owned by the same user as the
 *     agent (ownership check is by `media_assets.user_id`). When the
 *     asset row has no `user_id` (legacy data), the attach is rejected
 *     with {@see AgentPictureNotOwnedException} rather than silently
 *     allowed.
 *
 * The `toWireShape()` method is the single source of truth for the
 * `Agent.profile_picture` JSON contract — the enum colours are resolved
 * server-side, so the frontend never has to know the palette map.
 */
final class AgentPictureService extends ProfilePictureService
{
    /**
     * Default picture for new agents / backfilled existing agents. Used
     * by `getOrCreate()` and the backfill migration so the dashboard
     * renders the same defaults everywhere.
     */
    public const DEFAULT_ARCHETYPE = Archetype::Assistant;
    public const DEFAULT_PALETTE = Palette::Slate;

    /**
     * Build the wire-format `profile_picture` payload for an agent.
     * Returns the default avatar shape when the agent has no persisted
     * row — callers (HTTP list/create/show) get a uniform contract and
     * the dashboard renders the deterministic default everywhere instead
     * of falling back to initials.
     *
     * @return array<string, mixed>
     */
    public function toWireShape(int $agentId): array
    {
        return parent::toWireShape($agentId);
    }

    /**
     * Bind an uploaded image as the agent's picture. The asset must
     * already be ingested via MediaArchiveService::ingest() — this
     * method only sets the FK on the agent_pictures row.
     *
     * Ownership invariant: the asset's `user_id` must match the agent's
     * `user_id`. Mismatches (and assets with a NULL user_id) are rejected
     * with {@see AgentPictureNotOwnedException} — the HTTP path is
     * already user-scoped, but the service-level guard prevents future
     * callers from attaching a cross-user asset by accident.
     *
     * The avatar selection (archetype + variant + palette) is preserved
     * so removing the uploaded image reverts to the operator's previous
     * avatar choice rather than the hard-coded defaults.
     *
     * @throws AgentPictureNotOwnedException when the asset's user does not match.
     */
    public function attachImage(Agent $agent, MediaAsset $asset): AgentPicture
    {
        $this->assertAssetOwnership($agent, $asset);

        $picture = $this->getOrCreate((int) $agent->id);

        \Illuminate\Database\Capsule\Manager::table('agent_pictures')
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
     * Build the wire-format shape from an in-memory picture + (optionally)
     * pre-loaded media-asset. Use this when the caller has already eager-
     * loaded the picture + media relation, so {@see pictureToWire()} does
     * not re-query.
     */
    public function pictureToWireWithAsset(AgentPicture $picture, ?MediaAsset $asset): array
    {
        if ($picture->media_asset_id !== null && $asset instanceof MediaAsset) {
            return $this->imageWireShape($asset);
        }
        return $this->avatarWireShape($picture);
    }

    /**
     * Apply template metadata fields (`archetype`, `variant_key`,
     * `palette_key`) to an existing agent's picture. Returns null when
     * the metadata contains no picture fields — the importer then
     * leaves the agent's existing picture untouched.
     *
     * @param array<string, mixed> $metadata
     */
    public function applyTemplateMetadata(int $agentId, array $metadata): ?AgentPicture
    {
        if (!$this->hasTemplatePictureFields($metadata)) {
            return null;
        }

        $picture = $this->getOrCreate($agentId);
        $updates = [];

        if (isset($metadata['archetype']) && is_string($metadata['archetype'])) {
            $updates['archetype'] = $this->normaliseArchetype($metadata['archetype']);
        }
        if (array_key_exists('variant_key', $metadata)) {
            $variant = $metadata['variant_key'];
            if ($variant === null) {
                $updates['variant_key'] = null;
            } elseif (is_string($variant)) {
                $updates['variant_key'] = $this->normaliseVariantKey($variant);
            }
        }
        if (isset($metadata['palette_key']) && is_string($metadata['palette_key'])) {
            $updates['palette_key'] = $this->normalisePalette($metadata['palette_key']);
        }
        if ($updates === []) {
            return $picture;
        }

        $updates['updated_at'] = date(self::DATETIME_FORMAT);
        \Illuminate\Database\Capsule\Manager::table('agent_pictures')
            ->where('id', $picture->id)
            ->update($updates);
        $picture->refresh();
        return $picture;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function hasTemplatePictureFields(array $metadata): bool
    {
        return array_key_exists('archetype', $metadata)
            || array_key_exists('variant_key', $metadata)
            || array_key_exists('palette_key', $metadata);
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
        $variantKey = $picture->variant_key ?? $this->resolveVariantKey((int) $picture->agent_id);

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

    /**
     * Verify that `asset->user_id` matches `agent->user_id`. Reject assets
     * owned by a different user or by no user at all (NULL). The HTTP
     * layer guards this already, but the service contract is the
     * authoritative one — future internal callers (e.g. agent-tool image
     * picking) might not have that guarantee.
     */
    private function assertAssetOwnership(Agent $agent, MediaAsset $asset): void
    {
        if ($asset->user_id === null || (int) $asset->user_id !== (int) $agent->user_id) {
            throw AgentPictureNotOwnedException::forAsset((int) $agent->id, $asset->id);
        }
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
     * @return class-string<AgentPicture>
     */
    protected function pictureModel(): string
    {
        return AgentPicture::class;
    }

    protected function pictureTable(): string
    {
        return 'agent_pictures';
    }

    protected function subjectKey(): string
    {
        return 'agent_id';
    }
}
