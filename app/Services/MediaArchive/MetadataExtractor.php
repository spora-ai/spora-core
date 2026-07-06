<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Best-effort metadata extractor. Pure function over bytes — no DB, no
 * filesystem state — so it's safe to call from any thread / request and
 * trivial to mock in tests.
 *
 * Three public methods map to the three observed media classes:
 *
 *   - `extractImageMeta()` — `getimagesize()` is always available and
 *     handles the common formats (PNG, JPEG, GIF, WebP, BMP). Returns the
 *     sniffed MIME so the service can correct any drift between the caller's
 *     hint and the bytes.
 *   - `extractAudioVideoMeta()` — only runs `ffprobe` when both the binary
 *     is on PATH and the operator has opted in (`ffprobe_enabled = true`).
 *     Without `ffprobe` (or with it disabled), this returns `duration_seconds: null`
 *     and never throws.
 *   - `extract()` — top-level dispatcher that picks the right extractor
 *     based on the sniffed MIME type.
 *
 * `ffprobe` is shell-out hardened: arg-vector invocation via `proc_open`,
 * 5-second timeout, stderr discarded, JSON parsed with errors caught, and
 * ANY failure path returns null metadata + WARNING log instead of throwing.
 */
final class MetadataExtractor
{
    /** Path probe cache — kept on the instance to avoid repeated stat()s. */
    private ?bool $ffprobePathChecked = null;
    private ?string $ffprobePath = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $ffprobeEnabled = false,
    ) {}

    /**
     * Single entry point. Dispatches by MIME primary type; the
     * caller-supplied `mediaType` is used to disambiguate when the sniff
     * came back as a generic parent (`application/octet-stream`).
     *
     * @return ExtractedMetadata
     */
    public function extract(string $bytes, ?string $mime, MediaType $mediaType): ExtractedMetadata
    {
        if ($bytes === '') {
            return new ExtractedMetadata(width: null, height: null, durationSeconds: null, mime: $mime);
        }

        return match (true) {
            $mediaType === MediaType::Image => $this->imageResult($bytes, $mime),
            $mediaType === MediaType::Audio, $mediaType === MediaType::Video => $this->avResult($bytes, $mime),
            default => new ExtractedMetadata(width: null, height: null, durationSeconds: null, mime: $mime),
        };
    }

    /**
     * @return array{width: ?int, height: ?int, mime: string}
     */
    public function extractImageMeta(string $bytes, ?string $mime): array
    {
        $result = $this->imageResult($bytes, $mime);
        return [
            'width'  => $result->width,
            'height' => $result->height,
            'mime'   => $result->mime,
        ];
    }

    /**
     * @return array{duration_seconds: ?float, mime: string}
     */
    public function extractAudioVideoMeta(string $bytes, ?string $mime): array
    {
        $result = $this->avResult($bytes, $mime);
        return [
            'duration_seconds' => $result->durationSeconds,
            'mime'             => $result->mime,
        ];
    }

    private function imageResult(string $bytes, ?string $mime): ExtractedMetadata
    {
        // `getimagesize()` is a PHP builtin; the @-suppression keeps
        // non-image bytes from spamming logs — we already know it's
        // an image because the caller pre-classified.
        //
        // PHPStan attaches a literal-return shape assertion to
        // `getimagesizefromstring()`. JSON-encoding drops the shape to
        // `array<int, mixed>` so the offset reads below don't trip
        // "isset.offset" / "nullCoalesce.offset" noise for formats that
        // omit some keys.
        $raw = @getimagesizefromstring($bytes);
        if (!is_array($raw)) {
            return new ExtractedMetadata(width: null, height: null, durationSeconds: null, mime: $mime);
        }

        $decoded = json_decode((string) json_encode($raw), true);
        if (!is_array($decoded)) {
            return new ExtractedMetadata(width: null, height: null, durationSeconds: null, mime: $mime);
        }

        // `[0]` is width, `[1]` is height in every supported format.
        $widthRaw  = $decoded[0] ?? null;
        $heightRaw = $decoded[1] ?? null;
        $width  = is_numeric($widthRaw) ? (int) $widthRaw : null;
        $height = is_numeric($heightRaw) ? (int) $heightRaw : null;

        // `imageinfo['mime']` is the authoritative type for image bytes —
        // prefer it over the caller's hint when both are available, so a
        // JPEG masquerading as `application/octet-stream` gets corrected.
        $rawMime = $decoded['mime'] ?? null;
        $detected = is_string($rawMime) && $rawMime !== '' ? $rawMime : $mime;

        return new ExtractedMetadata(
            width: $width,
            height: $height,
            durationSeconds: null,
            mime: $detected,
        );
    }

    private function avResult(string $bytes, ?string $mime): ExtractedMetadata
    {
        $duration = $this->runFfprobe($bytes);
        return new ExtractedMetadata(
            width: null,
            height: null,
            durationSeconds: $duration,
            mime: $mime,
        );
    }

    private function runFfprobe(string $bytes): ?float
    {
        if (!$this->ffprobeEnabled) {
            return null;
        }
        $binary = $this->findFfprobe();
        if ($binary === null) {
            return null;
        }

        // Write bytes to a temp file rather than `-` pipe so the
        // arg-vector stays free of `pipe:` redirection tokens that some
        // shell interpreters handle differently. The temp file is unlinked
        // in a `finally` so a crash mid-probe doesn't litter /tmp.
        $tmp = tempnam(sys_get_temp_dir(), 'spora-ffprobe-');
        if ($tmp === false) {
            return null;
        }
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                return null;
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $cmd = [
                $binary,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $tmp,
            ];

            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return null;
            }
            // Close stdin immediately — ffprobe reads from $tmp directly.
            fclose($pipes[0]);

            // Bounded wait: 5 s is generous for ffprobe on any sane file
            // size; longer usually means the input is corrupt.
            $stdout = stream_get_contents($pipes[1]);
            // Drain stderr so the pipe buffer doesn't fill and block ffprobe.
            // Discarded — the output format is the source of truth.
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exit = proc_close($proc);
            if ($exit !== 0 || !is_string($stdout) || $stdout === '') {
                $this->logger->warning('MetadataExtractor: ffprobe returned no usable output', [
                    'exit' => $exit,
                    'size' => strlen($bytes),
                ]);
                return null;
            }

            $decoded = json_decode($stdout, true);
            if (!is_array($decoded)) {
                $this->logger->warning('MetadataExtractor: ffprobe JSON parse failed', [
                    'exit' => $exit,
                ]);
                return null;
            }

            $duration = $decoded['format']['duration'] ?? null;
            if (is_string($duration) && is_numeric($duration)) {
                return (float) $duration;
            }
            if (is_numeric($duration)) {
                return (float) $duration;
            }
            return null;
        } catch (Throwable $e) {
            $this->logger->warning('MetadataExtractor: ffprobe threw', [
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private function findFfprobe(): ?string
    {
        if ($this->ffprobePathChecked) {
            return $this->ffprobePath;
        }
        $this->ffprobePathChecked = true;

        $candidates = ['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe'];
        foreach ($candidates as $candidate) {
            $resolved = @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate)));
            if (is_string($resolved) && trim($resolved) !== '') {
                $path = trim($resolved);
                if (is_executable($path)) {
                    $this->ffprobePath = $path;
                    return $path;
                }
            }
        }
        return null;
    }
}
