<?php

declare(strict_types=1);

namespace Spora\Services\ProfilePictures;

/**
 * First-failure detail returned by
 * {@see ProfilePictureService::validatePayload()}. Holds the wire
 * envelope the controller emits on a 422 — the validator itself
 * stays HTTP-free so the service can be reused outside the
 * controller layer.
 */
final class ProfilePictureValidationError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {}
}
