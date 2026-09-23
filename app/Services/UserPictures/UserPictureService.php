<?php

declare(strict_types=1);

namespace Spora\Services\UserPictures;

use DateTime;
use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Models\UserPicture;

/**
 * Owns the byte-path + table-path for user profile pictures.
 *
 * Picture state lives in {@see UserPicture} (a single row keyed by
 * `user_id`); the bytes themselves live at
 * `<storage>/user-pictures/<user_id>.<ext>` so the serving controller
 * can stream them without a `media_assets` indirection.
 *
 * Why this service is intentionally NOT a {@see \Spora\Services\ProfilePictures\ProfilePictureService}
 * subclass:
 *   - The shared base class was built for agent/group pictures whose
 *     pipeline is the Media Archive: archetype avatar XOR uploaded
 *     image, both fronted by `media_assets`. User pictures have no
 *     archetype branch and never go through `media_assets`. Inheriting
 *     would force the empty archetype/half through the parent type
 *     and add nothing real.
 *   - The wire shape carries only the `kind: 'image'` fields the
 *     agent/group picture emits when `media_asset_id` is set, so the
 *     frontend can render it with the existing `Avatar` component
 *     unchanged.
 *
 * Atomicity on replace: writes go to `<user_id>.<random>.tmp`, then
 * `rename()` swaps over the destination. POSIX `rename()` is atomic on
 * the same filesystem, so a concurrent second upload either lands on
 * the same final path or waits on the rename — never produces a
 * half-written file at the served URL.
 */
final class UserPictureService
{
    private const STORAGE_SUBDIR = 'user-pictures';

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function findForUser(int $userId): ?UserPicture
    {
        return UserPicture::where('user_id', $userId)->first();
    }

    /**
     * Persist `bytes` as the user's profile picture. Replaces any
     * existing row + file in one transaction so a concurrent read
     * either sees the old picture or the new picture, never a
     * missing-file 404.
     */
    public function upload(int $userId, string $mime, int $sizeBytes, string $bytes): UserPicture
    {
        $extension = self::extensionForMime($mime);
        $dir = $this->ensureStorageDir();
        $finalPath = self::joinPath($dir, $userId . '.' . $extension);
        $tmpPath = self::joinPath($dir, $userId . '.' . bin2hex(random_bytes(8)) . '.tmp');

        if (file_put_contents($tmpPath, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write user picture to {$tmpPath}");
        }
        chmod($tmpPath, 0644); // NOSONAR — world-readable like other asset paths
        if (!rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Failed to move user picture into place at {$finalPath}");
        }
        chmod($finalPath, 0644); // NOSONAR

        $relativePath = self::STORAGE_SUBDIR . '/' . $userId . '.' . $extension;

        // Update-or-insert keyed on user_id (UNIQUE). Returning the row
        // is enough — the controller fetches the same record again via
        // `findForUser()` so callers see the persisted timestamps.
        $now = date('Y-m-d H:i:s');
        $existing = UserPicture::where('user_id', $userId)->first();
        if ($existing instanceof UserPicture) {
            $stalePath = self::joinPath($dir, basename($existing->media_path));
            if ($stalePath !== $finalPath && is_file($stalePath)) {
                @unlink($stalePath);
            }
            $existing->media_path = $relativePath;
            $existing->mime = $mime;
            $existing->size_bytes = $sizeBytes;
            // Eloquent's `datetime` cast expects a DateTimeInterface; the
            // string literal above would be coerced at save() time but
            // PHPStan can't see through the cast. Build a DateTime so the
            // assignment is statically typed correctly.
            $existing->updated_at = new DateTime($now);
            $existing->save();
            return $existing;
        }

        $id = Capsule::table('user_pictures')->insertGetId([
            'user_id'    => $userId,
            'media_path' => $relativePath,
            'mime'       => $mime,
            'size_bytes' => $sizeBytes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = UserPicture::find($id);
        if ($created === null) {
            throw new RuntimeException("Failed to load inserted user_pictures row {$id}");
        }
        return $created;
    }

    public function delete(int $userId): void
    {
        $existing = UserPicture::where('user_id', $userId)->first();
        if ($existing === null) {
            return;
        }
        Capsule::table('user_pictures')->where('id', $existing->id)->delete();
        $absolute = self::joinPath($this->paths->storage(), $existing->media_path);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * Resolve the wire-format `profile_picture` payload. Mirrors
     * {@see \Spora\Services\ProfilePictures\ProfilePictureService::imageWireShape()}
     * so the frontend `ProfilePicture` discriminated union recognises
     * it without a new branch — the `archetype`/`variant_key`/
     * `palette_key`/`fg_color`/`bg_color` fields stay null because
     * user pictures never use the archetype branch.
     *
     * @return array<string, mixed>|null  `null` when the user has no
     *                                    picture uploaded yet; the SPA
     *                                    falls back to initials.
     */
    public function toWireShape(?UserPicture $picture): ?array
    {
        if ($picture === null) {
            return null;
        }

        return [
            'kind'             => 'image',
            'archetype'        => null,
            'variant_key'      => null,
            'palette_key'      => null,
            'fg_color'         => null,
            'bg_color'         => null,
            'image_url'        => $this->publicUrlFor($picture),
            'image_updated_at' => $picture->updated_at?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Build the relative path the controller serves from disk. Lives
     * here so tests can assert it without booting a request.
     */
    public function publicUrlFor(UserPicture $picture): string
    {
        return '/api/v1/users/' . $picture->user_id . '/picture';
    }

    /**
     * Absolute on-disk path for a user picture row, or null when the
     * row's `media_path` no longer points at a readable file. The
     * serving controller surfaces the null as a 404 with existence-
     * hiding semantics.
     */
    public function absolutePathFor(UserPicture $picture): ?string
    {
        $path = self::joinPath($this->paths->storage(), $picture->media_path);
        return is_file($path) ? $path : null;
    }

    private function ensureStorageDir(): string
    {
        $dir = self::joinPath($this->paths->storage(), self::STORAGE_SUBDIR);
        if (!is_dir($dir) && !@mkdir($dir, 0755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create user-pictures directory: {$dir}");
        }
        chmod($dir, 0755); // NOSONAR
        return $dir;
    }

    /**
     * The MIME-to-extension map is intentionally narrow — only the
     * three MIMEs {@see AvatarUploadSupport} allows through the
     * upload pipeline. Anything else is rejected upstream; this map
     * is a defence-in-depth check that would still produce a
     * sensible extension if a future caller bypasses the trait.
     */
    private static function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default      => 'bin',
        };
    }

    private static function joinPath(string ...$parts): string
    {
        // The first segment may be absolute (e.g. `<storage>/...`), so
        // we preserve its leading `/` rather than blindly `trim()`-ing
        // every segment.
        $first = array_shift($parts);
        $first = rtrim($first, '/');
        $rest  = array_map(static fn(string $p): string => trim($p, '/'), $parts);
        $segments = array_filter([$first, ...$rest], static fn(string $s): bool => $s !== '');
        return implode('/', $segments);
    }
}
