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
 * The `<ext>` half is not a local decision: it is
 * {@see self::storedExtension()} applied to the SAME two row columns
 * (`mime_type`, `filename`) on both the writing and the reading side, so
 * every reader reconstructs the exact name {@see self::store()} wrote.
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

        $ext  = self::storedExtension($mime, $filename);
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
     * The extension comes from {@see self::storedExtension()} over the
     * row's own `mime_type` + `filename` — the same two columns
     * {@see self::store()} was handed on the way in, so this resolves the
     * exact name the writer produced. Passing `null` here for the filename
     * (the pre-fix behaviour) is what made every non-markdown local
     * derivative 404: `store()` took `thumbnail-256` from the name and this
     * looked for `webp` from the MIME.
     *
     * @return array{path: string, mime: string, length: int}
     */
    public function readFromAsset(MediaAsset $asset): array
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            throw new AssetStorageException("MediaAsset {$asset->id} has no asset_token");
        }
        $ext = self::storedExtension($asset->mime_type, $asset->filename);
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
     * The ONE rule for the `<token>.<ext>` suffix: the extension this store
     * writes, and therefore the extension every reader must look for.
     *
     * A caller-supplied filename wins, because that is the name
     * {@see self::store()} was handed and therefore the name on disk;
     * otherwise the MIME resolves through the single map,
     * {@see MediaArchiveService::extensionForMime()}, falling back to `bin`.
     *
     * Public and static precisely so the derivative-side readers reuse it
     * rather than re-deriving: {@see \Spora\Services\MediaArchive\DerivativePayloadStore::pathFor()}
     * and `ImageDerivativeProducer::readLocalBytes()` both need to rebuild
     * this suffix from a {@see MediaAsset} row and nothing else, and a
     * private MIME-only copy of this method is exactly the drift that made
     * every non-markdown local derivative unreadable — the OOXML MIME once
     * wrote `<token>.docx` (filename hint) and read back `<token>.bin`.
     *
     * The agreement holds under one stated condition: the row's `filename`
     * must be the filename the bytes were stored under. Every writer in
     * core (`MediaArchiveIngestPipeline`, `MediaDerivativeService`,
     * {@see \Spora\Plugins\Concerns\StoresBinaryAssets}) stores first and
     * persists that same name on the row, so a row written after this rule
     * shipped always round-trips. A row whose `filename` is later rewritten
     * would resolve to a path that no longer exists — there is no rename
     * path in the archive today, and `AssetReference` carries no name the
     * store could re-derive one from.
     */
    public static function storedExtension(?string $mime, ?string $filename): string
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
