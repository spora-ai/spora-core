<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Carries the HTTP status code + machine-readable error code from the
 * speech provider configuration endpoints back to
 * {@see \Spora\Http\SpeechProviderConfigController}, which catches it
 * once per route and maps it to the wire shape.
 *
 * Mirrors {@see SpeechTranscribeException}: named factories for each
 * documented failure mode so the controller never builds the JSON
 * envelope inline. The 401 path is handled by `AuthMiddleware`, not by
 * this exception — anonymous callers never reach the controller.
 *
 * Factories:
 *  - `notFound()`  → 404 `SPEECH_PROVIDER_CONFIG_NOT_FOUND`
 *  - `forbidden()` → 403 `SPEECH_PROVIDER_CONFIG_FORBIDDEN`
 *  - `validation()`→ 422 `SPEECH_PROVIDER_CONFIG_INVALID`
 */
final class SpeechProviderConfigException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'SPEECH_PROVIDER_CONFIG_NOT_FOUND', $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'SPEECH_PROVIDER_CONFIG_FORBIDDEN', $message);
    }

    public static function validation(string $message): self
    {
        return new self(422, 'SPEECH_PROVIDER_CONFIG_INVALID', $message);
    }
}
