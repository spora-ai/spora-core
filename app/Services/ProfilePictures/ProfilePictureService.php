<?php

declare(strict_types=1);

namespace Spora\Services\ProfilePictures;

use DateTimeInterface;
use InvalidArgumentException;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;

/**
 * Base class for the picture-editing service family
 * ({@see \Spora\Services\AgentPictures\AgentPictureService} and
 * {@see GroupPictureService}). Owns the cross-subject logic — the
 * archetype / palette / variant enum validation, the deterministic
 * variant hashing, and the wire-format shape — so each subject only
 * has to define what makes it unique (the table name, the defaults,
 * and the avatar-row → wire-shape mapping).
 *
 * Concrete subclasses implement:
 *   - {@see defaultArchetype()} / {@see defaultPalette()} — the fallback
 *     used when a row has no stored avatar metadata yet.
 *   - {@see pictureModel()} / {@see pictureTable()} — the Eloquent model
 *     + table name backing this subject's picture row.
 *   - {@see avatarWireShape()} — how to resolve an avatar-kind row into
 *     the wire-format shape (the only subject-specific shape since the
 *     default + image shapes are shared).
 *
 * Validation contract:
 *   - `normaliseArchetype()` / `normalisePalette()` accept `?string` —
 *     `null` returns the subject default, a known enum value returns
 *     itself, anything else throws `InvalidArgumentException`.
 *   - `normaliseVariantKey()` accepts `?string` — `null` returns `null`
 *     (caller should auto-derive via {@see resolveVariantKey()}), `v0`
 *     through `v2` returns itself, anything else throws.
 */
abstract class ProfilePictureService
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function getOrCreate(int $subjectId): mixed
    {
        $existing = $this->pictureModel()::where($this->subjectKey(), $subjectId)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->createDefaultPicture($subjectId);
    }

    /**
     * Insert the default picture row for a subject. Idempotent — looks
     * up the existing row first and returns it when one is already
     * present. Centralised so creation paths share the same defaults.
     */
    public function createDefaultPicture(int $subjectId): mixed
    {
        $existing = $this->pictureModel()::where($this->subjectKey(), $subjectId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $now = date(self::DATETIME_FORMAT);
        $id = \Illuminate\Database\Capsule\Manager::table($this->pictureTable())->insertGetId([
            $this->subjectKey()   => $subjectId,
            'archetype'           => $this->defaultArchetype()->value,
            'variant_key'         => null,
            'palette_key'         => $this->defaultPalette()->value,
            'media_asset_id'      => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $created = $this->pictureModel()::find($id);
        if ($created === null) {
            throw new InvalidArgumentException(
                "Failed to create default picture for {$this->subjectKey()} {$subjectId}.",
            );
        }
        return $created;
    }

    /**
     * Apply an archetype avatar selection. Any null argument leaves the
     * existing value untouched (partial update). Non-null values are
     * normalised via {@see normaliseArchetype()} / {@see normalisePalette()} /
     * {@see normaliseVariantKey()}.
     *
     * Switching to an archetype avatar always clears any uploaded image.
     *
     * @throws InvalidArgumentException on unknown archetype or palette.
     */
    public function updateAvatar(
        int $subjectId,
        ?string $archetype,
        ?string $variantKey,
        ?string $paletteKey,
    ): mixed {
        $picture = $this->getOrCreate($subjectId);

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

        \Illuminate\Database\Capsule\Manager::table($this->pictureTable())
            ->where('id', $picture->id)
            ->update($updates);
        $picture->refresh();
        return $picture;
    }

    /**
     * Clear the uploaded image and fall back to the previously selected
     * archetype avatar (or the defaults when none was ever picked). The
     * underlying `media_assets` row is NOT deleted — binary GC is a
     * separate ops concern.
     */
    public function detachImage(int $subjectId): mixed
    {
        $picture = $this->getOrCreate($subjectId);

        // When an archetype was set previously, preserve it. When only an
        // image was ever attached, fall back to the service defaults so
        // the subject always renders something meaningful.
        $hasArchetype = $picture->archetype !== null || $picture->palette_key !== null;

        \Illuminate\Database\Capsule\Manager::table($this->pictureTable())
            ->where('id', $picture->id)
            ->update([
                'media_asset_id' => null,
                'archetype'      => $hasArchetype ? $picture->archetype : $this->defaultArchetype()->value,
                'variant_key'    => $hasArchetype ? $picture->variant_key : null,
                'palette_key'    => $hasArchetype ? $picture->palette_key : $this->defaultPalette()->value,
                'updated_at'     => date(self::DATETIME_FORMAT),
            ]);
        $picture->refresh();
        return $picture;
    }

    /**
     * Build the wire-format `profile_picture` payload. Returns the
     * default avatar shape when the subject has no persisted row.
     *
     * @return array<string, mixed>
     */
    public function toWireShape(int $subjectId): array
    {
        $picture = $this->pictureModel()::where($this->subjectKey(), $subjectId)->first();
        if ($picture !== null) {
            return $this->pictureToWire($picture);
        }
        return $this->defaultWireShape($subjectId);
    }

    /**
     * @return array<string, mixed>
     */
    public function pictureToWire(mixed $picture): array
    {
        if ($picture->media_asset_id !== null) {
            $asset = MediaAsset::find($picture->media_asset_id);
            return $this->imageWireShape($asset instanceof MediaAsset ? $asset : null);
        }

        return $this->avatarWireShape($picture);
    }

    /**
     * Resolve the wire-format shape for a kind=image row. Returns null
     * fields everywhere a kind=avatar row would be populated so the
     * frontend's discriminated-union rendering does not need a guard.
     *
     * @return array<string, mixed>
     */
    public function imageWireShape(?MediaAsset $asset): array
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
     * Validate an archetype string against the enum. `null` returns the
     * subject default so callers can use this method directly on optional
     * payloads. Throws on unknown values so the controller can surface a
     * 422 with a clear field path.
     */
    public function normaliseArchetype(?string $raw): string
    {
        if ($raw === null) {
            return $this->defaultArchetype()->value;
        }
        $archetype = Archetype::tryFrom($raw);
        if ($archetype === null) {
            throw new InvalidArgumentException(sprintf(
                "Unknown archetype '%s'. Expected one of: %s.",
                $raw,
                implode(', ', array_map(static fn(Archetype $a): string => $a->value, Archetype::cases())),
            ));
        }
        return $archetype->value;
    }

    /**
     * Validate a palette_key string against the enum. Same nullable
     * contract as {@see normaliseArchetype()}.
     */
    public function normalisePalette(?string $raw): string
    {
        if ($raw === null) {
            return $this->defaultPalette()->value;
        }
        $palette = Palette::tryFrom($raw);
        if ($palette === null) {
            throw new InvalidArgumentException(sprintf(
                "Unknown palette_key '%s'. Expected one of: %s.",
                $raw,
                implode(', ', array_map(static fn(Palette $p): string => $p->value, Palette::cases())),
            ));
        }
        return $palette->value;
    }

    /**
     * Validate a variant_key string. `null` returns `null` (caller should
     * auto-derive via {@see resolveVariantKey()}). Accepts `v0|v1|v2`
     * only — three variants per archetype is the first-cut budget.
     */
    public function normaliseVariantKey(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!preg_match('/^v[0-2]$/', $raw)) {
            throw new InvalidArgumentException(sprintf(
                "Unknown variant_key '%s'. Expected one of: v0, v1, v2.",
                $raw,
            ));
        }
        return $raw;
    }

    /**
     * Validate the wire-level `profile_picture` object — accepts the
     * raw `$body['profile_picture']` value (which may be null, scalar,
     * list, or assoc array) and returns either the normalised payload
     * or the first validation error.
     *
     * Thin delegate to {@see ProfilePicturePayloadValidator::validate()}
     * so the wire contract lives in one HTTP-free helper that both
     * {@see \Spora\Http\AgentController} and
     * {@see \Spora\Http\GroupController} share. The error codes
     * (`PROFILE_PICTURE_TYPE`, `PROFILE_PICTURE_UNKNOWN_KEY`,
     * `PROFILE_PICTURE_VALUE`) are part of the public surface that
     * the operator UI keys off.
     *
     * "Key absent" is NOT handled here — the controller short-circuits
     * before calling this method when the body's `profile_picture` key
     * is missing, since that case means "no avatar update". An
     * explicit `null` IS handled and produces a 422, because the
     * caller asked us to clear the picture and we can't clear what
     * wasn't an object to begin with.
     *
     * @param mixed $picture raw `$body['profile_picture']` value (caller
     *                       guarantees the key is present)
     * @return array<string, string>|ProfilePictureValidationError
     */
    public function validatePayload(mixed $picture): array|ProfilePictureValidationError
    {
        return ProfilePicturePayloadValidator::validate($picture, $this);
    }

    /**
     * Deterministic 3-bucket variant selection. Same algorithm on the
     * frontend so the resolved variant_key is identical on both sides
     * (defence-in-depth — the server is the source of truth, but the
     * frontend can render the same tile before the API responds).
     */
    public function resolveVariantKey(int $seed): string
    {
        $hash = $this->fnv1a($seed);
        return 'v' . ($hash % 3);
    }

    /**
     * 32-bit FNV-1a hash.
     */
    public function fnv1a(int $input): int
    {
        $h = 0x811c9dc5;
        $s = (string) $input;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 0x01000193) & 0xFFFFFFFF;
        }
        return $h;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultWireShape(int $subjectId): array
    {
        return [
            'kind'             => 'avatar',
            'archetype'        => $this->defaultArchetype()->value,
            'variant_key'      => $this->resolveVariantKey($subjectId),
            'palette_key'      => $this->defaultPalette()->value,
            'fg_color'         => $this->defaultPalette()->foreground(),
            'bg_color'         => $this->defaultPalette()->background(),
            'image_url'        => null,
            'image_updated_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function avatarWireShape(mixed $picture): array;

    abstract protected function defaultArchetype(): Archetype;

    abstract protected function defaultPalette(): Palette;

    /**
     * @return class-string
     */
    abstract protected function pictureModel(): string;

    abstract protected function pictureTable(): string;

    /**
     * Column name on the picture row that holds the subject's id
     * (`agent_id`, `group_id`, …).
     */
    abstract protected function subjectKey(): string;
}
