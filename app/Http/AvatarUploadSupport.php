<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Http\Exceptions\AvatarFileReadFailedException;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared multipart-upload pipeline for the Agent and Group picture
 * controllers. The two controllers are mirror images of each other
 * (same 1 MiB cap, same PNG/JPEG/WebP MIME allowlist, same byte-path
 * decoding) — extracting the pipeline here drops ~200 lines of
 * duplicated SonarQube-flagged copy-paste.
 *
 * The pipeline is intentionally split into small helpers (each
 * returning `array|JsonResponse` / `?JsonResponse`) so each function
 * stays under the S1142 3-return ceiling.
 *
 * The trait assumes the using class uses `JsonControllerHelpers` (for
 * `$this->error(...)`) and has a `private readonly MimeSniffer $sniffer`
 * constructor-promoted property.
 *
 * @property MimeSniffer $sniffer
 */
trait AvatarUploadSupport
{
    /**
     * 1 MiB. Bigger than the visual quality needs require (≤ 256x256 px
     * PNG under 64 KB is already lossless) and small enough to keep the
     * repeated dashboard renders fast.
     */
    private const MAX_AVATAR_BYTES = 1 * 1024 * 1024;

    /**
     * Avatar MIME allowlist. Avatars are always raster images regardless
     * of whether the subject's LLM accepts image input. Bytes are
     * sniffed (the client `Content-Type` header is ignored), then
     * decoded to confirm PHP can actually render them.
     *
     * @var list<string>
     */
    private const ALLOWED_AVATAR_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /**
     * @return array{file: UploadedFile, bytes: string}|JsonResponse
     */
    private function prepareUpload(Request $request): array|JsonResponse
    {
        $file = $this->extractUploadedFile($request);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $bytes = $this->readAndValidateUploadBytes($file);
        if ($bytes instanceof JsonResponse) {
            return $bytes;
        }

        return ['file' => $file, 'bytes' => $bytes];
    }

    /**
     * @return UploadedFile|JsonResponse
     */
    private function extractUploadedFile(Request $request): UploadedFile|JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST);
        }

        $sizeError = $this->validateUploadSize($file);
        if ($sizeError !== null) {
            return $sizeError;
        }

        return $file;
    }

    private function readAndValidateUploadBytes(UploadedFile $file): string|JsonResponse
    {
        $bytes = $this->readFileBytes($file);
        if (strlen($bytes) > self::MAX_AVATAR_BYTES) {
            return $this->error(
                'PAYLOAD_TOO_LARGE',
                sprintf('Avatar image exceeds %d bytes.', self::MAX_AVATAR_BYTES),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $mimeError = $this->validateUploadMime($bytes);
        if ($mimeError !== null) {
            return $mimeError;
        }

        return $bytes;
    }

    private function validateUploadSize(UploadedFile $file): ?JsonResponse
    {
        if (!$file->isValid()) {
            return $this->error('BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() > self::MAX_AVATAR_BYTES) {
            return $this->error(
                'PAYLOAD_TOO_LARGE',
                sprintf('Avatar image exceeds %d bytes.', self::MAX_AVATAR_BYTES),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }
        return null;
    }

    private function validateUploadMime(string $bytes): ?JsonResponse
    {
        $sniffedMime = strtolower($this->sniffer->sniffFromBytes($bytes));
        if (!in_array($sniffedMime, self::ALLOWED_AVATAR_MIMES, true)) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not a supported avatar image type.', $sniffedMime),
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                'Avatar image bytes could not be decoded.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        return null;
    }

    private function readFileBytes(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            throw AvatarFileReadFailedException::onPath($file->getPathname());
        }
        return $bytes;
    }

    /**
     * Sanitise a client-supplied filename. Returns null when the client
     * didn't supply one — MediaArchive generates a sensible fallback.
     */
    private function sanitisedClientName(UploadedFile $file): ?string
    {
        $clientName = $file->getClientOriginalName();
        return $clientName !== '' ? Utf8Sanitizer::scrubString($clientName) : null;
    }
}