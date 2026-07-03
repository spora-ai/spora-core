<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManagerInterface;

/**
 * Disk-backed {@see AssetStore}. Writes the payload to
 * `<storage>/assets/<token>.<ext>` and returns a URL pointing at
 * `GET /api/v1/assets/<token>.<ext>`.
 *
 * Authorization is the URL itself: tokens are HMAC-SHA256 over
 * `<ext>|<YYYYMMDD>` truncated to 32 hex chars, signed with the master
 * key from {@see SecurityManagerInterface::masterKey()}. The day stamp
 * means a token from yesterday stops working — no separate token table,
 * no DB lookup. Files become unrecoverable after the day rolls over; a
 * separate {@see \Spora\Console\Commands\AssetGcCommand} sweeps the
 * orphaned bytes lazily.
 *
 * Pros: stable URLs that survive message history; multi-megabyte payloads
 *       don't bloat the chat HTML; no auth middleware required on the
 *       serving route (cookies still attach for same-origin requests).
 * Cons: depends on the storage directory being writable and on
 *       {@see SecurityManagerInterface} being available.
 */
final class LocalAssetStore implements AssetStore
{
    private const MIME_FOR_EXT = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'm4a'  => 'audio/mp4',
        'flac' => 'audio/flac',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mov'  => 'video/quicktime',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
    ];

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
            throw new RuntimeException("Failed to create asset directory: {$dir}");
        }
        // World-readable is fine: the URL is unguessable. PHP-FPM and the
        // web server may run as different users; 0700 would break that.
        chmod($dir, 0755);

        $token = $this->mintToken($ext);
        $path  = $dir . '/' . $token . '.' . $ext;

        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write asset to {$path}");
        }
        chmod($path, 0644);

        return new AssetReference(
            url: '/api/v1/assets/' . $token . '.' . $ext,
            mode: 'local',
            token: $token,
        );
    }

    /**
     * Resolves a public-facing filename (e.g. `abc123def….mp3`) back to the
     * absolute path and MIME type, after verifying the HMAC. Returns null
     * when the token is invalid or the file is missing — callers should
     * respond with 404 in that case.
     *
     * @return array{path: string, mime: string}|null
     */
    public function resolve(string $filename): ?array
    {
        $token = pathinfo($filename, PATHINFO_FILENAME);
        $ext   = pathinfo($filename, PATHINFO_EXTENSION);

        if ($token === '' || $ext === '') {
            return null;
        }

        $expected = $this->signToken($ext);
        // Constant-time compare so a partial-prefix brute force doesn't
        // leak which character is wrong via response timing.
        if (! hash_equals($expected, substr($token, 0, strlen($expected)))) {
            return null;
        }
        // Tokens are `<hmac-32hex>.<random-16hex>` — verify suffix shape
        // before touching the filesystem.
        $random = substr($token, strlen($expected) + 1);
        if (strlen($random) !== 16 || ! ctype_xdigit($random)) {
            return null;
        }

        $path = $this->paths->storage('assets') . '/' . $filename;
        if (! is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => self::MIME_FOR_EXT[strtolower($ext)] ?? 'application/octet-stream',
        ];
    }

    private function pickExtension(?string $mime, ?string $filename): string
    {
        if (is_string($filename) && $filename !== '') {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== '') {
                return $ext;
            }
        }
        if (is_string($mime) && $mime !== '') {
            $fromMime = [
                'audio/mpeg'    => 'mp3',
                'audio/mp3'     => 'mp3',
                'audio/wav'     => 'wav',
                'audio/x-wav'   => 'wav',
                'audio/ogg'     => 'ogg',
                'audio/mp4'     => 'm4a',
                'audio/x-m4a'   => 'm4a',
                'audio/flac'    => 'flac',
                'video/mp4'     => 'mp4',
                'video/webm'    => 'webm',
                'video/quicktime' => 'mov',
                'image/jpeg'    => 'jpg',
                'image/png'     => 'png',
                'image/gif'     => 'gif',
                'image/webp'    => 'webp',
                'image/svg+xml' => 'svg',
                'application/pdf' => 'pdf',
                'text/plain'    => 'txt',
            ];
            if (isset($fromMime[strtolower($mime)])) {
                return $fromMime[strtolower($mime)];
            }
        }
        return 'bin';
    }

    private function mintToken(string $ext): string
    {
        return $this->signToken($ext) . '.' . bin2hex(random_bytes(8));
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
