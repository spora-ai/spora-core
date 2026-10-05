<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Paths;
use Spora\Core\SecurityManagerInterface;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;

/**
 * Disk-backed {@see AssetStore}. Writes the payload to
 * `<storage>/assets/<asset_token>.<ext>`; the row's `asset_token` (32 hex
 * chars of random bytes) is what {@see self::readFromAsset()} looks up.
 *
 * The pre-refactor {@see self::resolve()} HMAC-token scheme is kept for
 * legacy rows whose URL was returned to the LLM before the migration
 * — those keep serving until they age out.
 */
final class LocalAssetStore implements AssetStore
{
    public function __construct(
        private readonly Paths $paths,
        private readonly SecurityManagerInterface $security,
        private readonly int $maxBytes = 50 * 1024 * 1024,
    ) {}

    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
    {
        $size = strlen($bytes);
        if ($size > $this->maxBytes) {
            throw new AssetTooLargeException(sprintf(
                'Asset of %d bytes exceeds LocalAssetStore ceiling of %d bytes. '
                    . 'Raise asset_store.max_bytes if the payload is genuinely needed.',
                $size,
                $this->maxBytes,
            ));
        }

        $ext  = $this->pickExtension($mime, $filename);
        $dir  = $this->paths->storage('assets');
        if (! is_dir($dir) && ! @mkdir($dir, 0755, recursive: true) && ! is_dir($dir)) {
            throw new AssetStorageException("Failed to create asset directory: {$dir}");
        }
        // World-readable: PHP-FPM and the web server may run as different
        // users; 0700 would break that. Authorization is the unguessable
        // URL filename, not filesystem perms.
        chmod($dir, 0755); // NOSONAR

        $token = bin2hex(random_bytes(16));
        $path  = $dir . '/' . $token . '.' . $ext;

        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new AssetStorageException("Failed to write asset to {$path}");
        }
        chmod($path, 0644); // NOSONAR

        return new AssetReference(
            url: '/api/v1/assets/' . $token . '.' . $ext,
            mode: 'local',
            token: $token,
        );
    }

    /**
     * Resolves a legacy HMAC-token filename (e.g. `abc123….<random-hex>.mp3`)
     * back to the absolute path and MIME type, after verifying the daily
     * HMAC. Returns null when the token is invalid or the file is missing —
     * callers should respond with 404 in that case. Kept for backwards
     * compatibility with rows created before `fix/opaque-asset-urls`;
     * new local-mode rows are served by {@see self::readFromAsset()}.
     *
     * Security note: `$filename` arrives URL-decoded from the router
     * (FastRoute calls `urldecode()` on path vars), so a request like
     * `…%2F..%2F..%2Fconfig.php` would resolve to a path outside
     * `<storage>/assets/`. We defend in depth by validating the full
     * filename against a strict regex of `[a-f0-9.]+` BEFORE doing any
     * string concatenation or filesystem access.
     *
     * @return array{path: string, mime: string}|null
     */
    public function resolve(string $filename): ?array
    {
        // Single guard block — every reason to reject the request is
        // collected here so {@see resolve()} has one happy-path return.
        // Each condition comments the *threat* it defends against.
        //
        // 1. Length-bound prevents regex DoS on huge inputs.
        // 2. Strict regex keeps slashes/backslashes/dots/NULs out so a
        //    URL-decoded `%2F` can't build a traversal path.
        // 3. Empty token/ext catches the corner case of a regex match on
        //    an oddly-shaped filename.
        // 4. Constant-time HMAC compare — partial-prefix brute force
        //    shouldn't leak which char is wrong via response timing.
        // 5. Suffix-shape check — tokens are `<hmac-32hex>.<random-16hex>`;
        //    a forged random suffix is rejected before we touch disk.
        $token = pathinfo($filename, PATHINFO_FILENAME);
        $ext   = pathinfo($filename, PATHINFO_EXTENSION);
        if ($token === '' || $ext === ''
            || strlen($filename) > 128
            || ! preg_match('/^[a-f0-9]+\.[a-f0-9]+\.[a-z0-9]+$/', $filename)
            || ! hash_equals($this->signToken($ext), substr($token, 0, 32))
            || ! ctype_xdigit(substr($token, 33))
            || strlen(substr($token, 33)) !== 16
        ) {
            return null;
        }

        $path = $this->paths->storage('assets') . '/' . $filename;
        if (! is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => self::mimeForExtension($ext),
        ];
    }

    /**
     * Resolve a {@see MediaAsset} row's local-mode payload to its on-disk
     * file. Used by {@see \Spora\Http\AssetController} after a UUID lookup
     * so that the `/api/v1/assets/<uuid>` opaque URL resolves without
     * exposing the underlying HMAC-token filename.
     *
     * @return array{path: string, mime: string, length: int}
     */
    public function readFromAsset(MediaAsset $asset): array
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            throw new AssetStorageException("MediaAsset {$asset->id} has no asset_token");
        }
        $ext = $this->pickExtension($asset->mime_type, null);
        $path = $this->paths->storage('assets') . '/' . $token . '.' . $ext;
        if (!is_file($path)) {
            throw new AssetStorageException("Local asset file missing: {$path}");
        }

        return [
            'path'   => $path,
            'mime'   => self::mimeForExtension($ext),
            'length' => (int) filesize($path),
        ];
    }

    /**
     * The extension this store writes and the extension
     * {@see self::readFromAsset()} must find. Both sides resolve the MIME
     * through {@see MediaArchiveService::extensionForMime()}, the single
     * map — this class used to keep a private copy, which had already
     * drifted: the OOXML MIME wrote `<token>.docx` (filename hint) and read
     * back `<token>.bin`, so every local-mode `.docx` upload 404ed.
     */
    private function pickExtension(?string $mime, ?string $filename): string
    {
        if (is_string($filename) && $filename !== '') {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== '') {
                return $ext;
            }
        }

        return MediaArchiveService::extensionForMime($mime) ?? 'bin';
    }

    /** Canonical MIME for a stored extension, or octet-stream when unknown. */
    private static function mimeForExtension(string $ext): string
    {
        return MediaArchiveService::mimeForExtension($ext) ?? 'application/octet-stream';
    }

    private function signToken(string $ext): string
    {
        // Daily-rotating nonce means a stolen token expires even if the
        // file is left on disk. The HMAC binds the extension so swapping
        // `<token>.mp3` → `<token>.exe` doesn't accidentally serve as a
        // different content type.
        $day = gmdate('Ymd');
        return substr(
            hash_hmac('sha256', $ext . '|' . $day, $this->security->masterKey()),
            0,
            32,
        );
    }
}
