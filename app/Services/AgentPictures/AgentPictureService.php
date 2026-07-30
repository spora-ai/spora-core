<?php

declare(strict_types=1);

namespace Spora\Services\AgentPictures;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\MediaAsset;
use Spora\Services\Exceptions\AgentPictureNotOwnedException;

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
final class AgentPictureService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Default picture for new agents / backfilled existing agents. Used
     * by `getOrCreate()` and the backfill migration so the dashboard
     * renders the same defaults everywhere.
     */
    public const DEFAULT_ARCHETYPE = Archetype::Assistant;
    public const DEFAULT_PALETTE = Palette::Slate;

    public function getOrCreate(int $agentId): AgentPicture
    {
        $existing = AgentPicture::where('agent_id', $agentId)->first();
        if ($existing instanceof AgentPicture) {
            return $existing;
        }

        return $this->createDefaultPicture($agentId);
    }

    /**
     * Insert the default picture row for an agent. Idempotent — looks up
     * the existing row first and returns it when one is already present.
     * Centralised so creation paths (service, importer, template apply)
     * share the same defaults.
     */
    public function createDefaultPicture(int $agentId): AgentPicture
    {
        $existing = AgentPicture::where('agent_id', $agentId)->first();
        if ($existing instanceof AgentPicture) {
            return $existing;
        }

        $now = date(self::DATETIME_FORMAT);
        $id = Capsule::table('agent_pictures')->insertGetId([
            'agent_id'       => $agentId,
            'archetype'      => self::DEFAULT_ARCHETYPE->value,
            'variant_key'    => null,
            'palette_key'    => self::DEFAULT_PALETTE->value,
            'media_asset_id' => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $created = AgentPicture::find($id);
        if ($created === null) {
            throw new InvalidArgumentException("Failed to create default picture for agent {$agentId}.");
        }
        return $created;
    }

    /**
     * Apply an archetype avatar selection. Any null argument leaves the
     * existing value untouched (partial update). Non-null values are
     * normalised: `archetype`/`palette_key` against the enum, `variant_key`
     * against the `v0|v1|v2` regex.
     *
     * @throws InvalidArgumentException on unknown archetype or palette.
     */
    public function updateAvatar(
        int $agentId,
        ?string $archetype,
        ?string $variantKey,
        ?string $paletteKey,
    ): AgentPicture {
        $picture = $this->getOrCreate($agentId);

        $updates = [];
        if ($archetype !== null) {
            $updates['archetype'] = $this->normaliseArchetype($archetype);
        }
        if ($variantKey !== null) {
            $updates['variant_key'] = $this->normaliseVariantKey($variantKey);
        }
        if ($paletteKey !== null) {
            $updates['palette_key'] = $this->normalisePalette($paletteKey);
        }
        if ($updates === []) {
            return $picture;
        }

        // Switching to an archetype avatar always clears any uploaded image.
        $updates['media_asset_id'] = null;
        $updates['updated_at'] = date(self::DATETIME_FORMAT);

        Capsule::table('agent_pictures')
            ->where('id', $picture->id)
            ->update($updates);
        $picture->refresh();
        return $picture;
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

        Capsule::table('agent_pictures')
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
     * Clear the uploaded image and fall back to the previously selected
     * archetype avatar (or the defaults when none was ever picked). The
     * underlying `media_assets` row is NOT deleted — binary GC is a
     * separate ops concern (see backlog).
     */
    public function detachImage(int $agentId): AgentPicture
    {
        $picture = $this->getOrCreate($agentId);

        // When an archetype was set previously, preserve it. When only an
        // image was ever attached, fall back to the service defaults so
        // the agent always renders something meaningful.
        $hasArchetype = $picture->archetype !== null || $picture->palette_key !== null;

        Capsule::table('agent_pictures')
            ->where('id', $picture->id)
            ->update([
                'media_asset_id' => null,
                'archetype'      => $hasArchetype ? $picture->archetype : self::DEFAULT_ARCHETYPE->value,
                'variant_key'    => $hasArchetype ? $picture->variant_key : null,
                'palette_key'    => $hasArchetype ? $picture->palette_key : self::DEFAULT_PALETTE->value,
                'updated_at'     => date(self::DATETIME_FORMAT),
            ]);
        $picture->refresh();
        return $picture;
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
        Capsule::table('agent_pictures')
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
        $picture = AgentPicture::where('agent_id', $agentId)->first();
        if ($picture instanceof AgentPicture) {
            return $this->pictureToWire($picture);
        }
        return $this->defaultWireShape($agentId);
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
     * @return array<string, mixed>
     */
    private function defaultWireShape(int $agentId): array
    {
        $variantKey = $this->resolveVariantKey($agentId);
        return [
            'kind'             => 'avatar',
            'archetype'        => self::DEFAULT_ARCHETYPE->value,
            'variant_key'      => $variantKey,
            'palette_key'      => self::DEFAULT_PALETTE->value,
            'fg_color'         => self::DEFAULT_PALETTE->foreground(),
            'bg_color'         => self::DEFAULT_PALETTE->background(),
            'image_url'        => null,
            'image_updated_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pictureToWire(AgentPicture $picture): array
    {
        if ($picture->media_asset_id !== null) {
            $asset = MediaAsset::find($picture->media_asset_id);
            return $this->imageWireShape($asset instanceof MediaAsset ? $asset : null);
        }

        return $this->avatarWireShape($picture);
    }

    /**
     * @return array<string, mixed>
     */
    private function imageWireShape(?MediaAsset $asset): array
    {
        return [
            'kind'             => 'image',
            'archetype'        => null,
            'variant_key'      => null,
            'palette_key'      => null,
            'fg_color'         => null,
            'bg_color'         => null,
            'image_url'        => $asset !== null ? $asset->asset_url : null,
            'image_updated_at' => $asset !== null && $asset->updated_at !== null
                ? $asset->updated_at->format(DateTimeInterface::ATOM)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function avatarWireShape(AgentPicture $picture): array
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

    private function resolveVariantKey(int $agentId): string
    {
        $hash = $this->fnv1a((string) $agentId);
        $index = $hash % 3;
        return 'v' . $index;
    }

    /**
     * Validate an archetype string against the enum. Throws on unknown
     * values so the controller can surface a 422 with a clear field path.
     */
    public function normaliseArchetype(string $value): string
    {
        $archetype = Archetype::tryFrom($value);
        if ($archetype === null) {
            throw new InvalidArgumentException(sprintf(
                "Unknown archetype '%s'. Expected one of: %s.",
                $value,
                implode(', ', array_map(static fn(Archetype $a): string => $a->value, Archetype::cases())),
            ));
        }
        return $archetype->value;
    }

    /**
     * Validate a palette_key string against the enum. Throws on unknown
     * values so the controller can surface a 422 with a clear field path.
     */
    public function normalisePalette(string $value): string
    {
        $palette = Palette::tryFrom($value);
        if ($palette === null) {
            throw new InvalidArgumentException(sprintf(
                "Unknown palette_key '%s'. Expected one of: %s.",
                $value,
                implode(', ', array_map(static fn(Palette $p): string => $p->value, Palette::cases())),
            ));
        }
        return $palette->value;
    }

    /**
     * Validate a variant_key string. Accepts `v0|v1|v2` only — three
     * variants per archetype is the first-cut budget (see
     * `backlog/agent-profile-picture.md`).
     */
    public function normaliseVariantKey(string $value): string
    {
        if (!preg_match('/^v[0-2]$/', $value)) {
            throw new InvalidArgumentException(sprintf(
                "Unknown variant_key '%s'. Expected one of: v0, v1, v2.",
                $value,
            ));
        }
        return $value;
    }

    /**
     * 32-bit FNV-1a hash. Same algorithm as the frontend's variant
     * selection so the resolved variant_key is identical on both sides
     * (defence-in-depth — the server is the source of truth, but the
     * frontend can render the same tile before the API responds).
     */
    private function fnv1a(string $s): int
    {
        $h = 0x811c9dc5;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 0x01000193) & 0xFFFFFFFF;
        }
        return $h;
    }
}
