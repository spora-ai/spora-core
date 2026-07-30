<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by AgentPictureController::readFileBytes when the multipart
 * upload could not be read off disk. Caught by the Kernel and converted
 * to a 500 JSON response.
 */
final class AvatarFileReadFailedException extends RuntimeException
{
    public static function onPath(string $path): self
    {
        return new self(sprintf('Could not read uploaded file at %s.', $path));
    }
}
