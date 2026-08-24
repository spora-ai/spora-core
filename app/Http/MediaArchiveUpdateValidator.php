<?php

declare(strict_types=1);

namespace Spora\Http;

/**
 * PATCH /api/v1/media/{id} field validators.
 *
 * Pulled out of {@see MediaArchiveController} so the controller stays
 * under the SonarCloud S1448 20-method cap. Each method returns the
 * first error string it finds for the field, or null when the payload
 * passes. `null` is the "absent field" sentinel too — the controller
 * treats absent vs present-but-valid identically (no-op for the patch).
 */
final class MediaArchiveUpdateValidator
{
    /**
     * `filename` accepts `null` (clears) or a 1-255 char string.
     * Longer strings are rejected to match the column width and keep
     * the listing-render from clipping in the UI.
     *
     * @param array<string, mixed> $body
     */
    public static function validateFilename(array $body): ?string
    {
        if (!array_key_exists('filename', $body)) {
            return null;
        }
        $filename = $body['filename'];
        if ($filename !== null && (!is_string($filename) || strlen($filename) > 255)) {
            return 'filename must be a string up to 255 characters.';
        }
        return null;
    }

    /**
     * Shared validator for `tags` and `metadata`: both reject non-null
     * non-array payloads. The error message is caller-supplied so the
     * field name surfaces in the response envelope.
     *
     * @param array<string, mixed> $body
     */
    public static function validateArray(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_array($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * Shared validator for `prompt` and `markdown_content`: both reject
     * non-null non-string payloads.
     *
     * @param array<string, mixed> $body
     */
    public static function validateString(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_string($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * `is_public` is the only boolean field on the patch surface. A
     * non-boolean here is always an error (no `null` semantic — the
     * toggle is on or off, never cleared).
     *
     * @param array<string, mixed> $body
     */
    public static function validateBool(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        if (!is_bool($body[$field])) {
            return $errorMessage;
        }
        return null;
    }
}
