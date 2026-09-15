<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Carries the HTTP status code + machine-readable error code from the
 * speech transcribe endpoint back to {@see \Spora\Http\SpeechTranscribeController::transcribe()},
 * which catches it once and maps it to the wire shape.
 *
 * Centralising the status + code on the exception keeps each branch of
 * the request flow free of `return new JsonResponse(...)` boilerplate,
 * which is what keeps the controller method's `return` count below the
 * Sonar brain-overload threshold.
 */
final class SpeechTranscribeException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, 'UNAUTHORIZED', $message);
    }

    public static function validation(string $message): self
    {
        return new self(422, 'VALIDATION_ERROR', $message);
    }

    public static function invalidAudio(string $message): self
    {
        return new self(422, 'INVALID_AUDIO', $message);
    }

    public static function mediaNotFound(string $message): self
    {
        return new self(404, 'MEDIA_NOT_FOUND', $message);
    }

    public static function providerUnavailable(string $message): self
    {
        return new self(503, 'SPEECH_PROVIDER_UNAVAILABLE', $message);
    }

    public static function providerFailed(string $message): self
    {
        return new self(502, 'SPEECH_PROVIDER_FAILED', $message);
    }
}
