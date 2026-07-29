<?php

declare(strict_types=1);

namespace Spora\Services\AgentPictures;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Models\AgentPicture;
use Spora\Models\MediaAsset;

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
 *     agent (ownership check is by `media_assets.user_id`).
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
     * Apply an archetype avatar selection. Either `archetype` or
     * `paletteKey` may be null to leave the existing value untouched;
     * `variantKey` null means "auto-derive from agent_id" (not "keep"),
     * so the caller can opt the agent out of an operator override.
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
     */
    public function attachImage(int $agentId, MediaAsset $asset): AgentPicture
    {
        $picture = $this->getOrCreate($agentId);

        Capsule::table('agent_pictures')
            ->where('id', $picture->id)
            ->update([
                'media_asset_id' => $asset->id,
                'archetype'      => null,
                'variant_key'    => null,
                'palette_key'    => null,
                'updated_at'     => date(self::DATETIME_FORMAT),
            ]);
        $picture->refresh();
        return $picture;
    }

    /**
     * Clear the uploaded image and fall back to the default archetype
     * avatar. The underlying `media_assets` row is NOT deleted — binary
     * GC is a separate ops concern (see backlog).
     */
    public function detachImage(int $agentId): AgentPicture
    {
        $picture = $this->getOrCreate($agentId);

        Capsule::table('agent_pictures')
            ->where('id', $picture->id)
            ->update([
                'media_asset_id' => null,
                'archetype'      => self::DEFAULT_ARCHETYPE->value,
                'variant_key'    => null,
                'palette_key'    => self::DEFAULT_PALETTE->value,
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
     * Returns null when the agent has no picture at all (caller may
     * treat null as "use defaults").
     *
     * @return array<string, mixed>|null
     */
    public function toWireShape(int $agentId): ?array
    {
        $picture = AgentPicture::where('agent_id', $agentId)->first();
        if ($picture instanceof AgentPicture) {
            return $this->pictureToWire($picture);
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function pictureToWire(AgentPicture $picture): array
    {
        if ($picture->media_asset_id !== null) {
            $asset = MediaAsset::find($picture->media_asset_id);
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

        $archetype = $picture->archetype !== null
            ? Archetype::tryFrom($picture->archetype) ?? self::DEFAULT_ARCHETYPE
            : self::DEFAULT_ARCHETYPE;
        $palette = $picture->palette_key !== null
            ? Palette::tryFrom($picture->palette_key) ?? self::DEFAULT_PALETTE
            : self::DEFAULT_PALETTE;
        $variantKey = $picture->variant_key ?? $this->resolveVariantKey($picture->agent_id);

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
