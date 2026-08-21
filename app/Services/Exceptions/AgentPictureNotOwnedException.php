<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an upload is being attached to a profile picture but the
 * underlying media asset is not owned by the same user as the picture's
 * subject (agent or group). The HTTP layer guards this today, but the
 * service contract enforces it independently so future internal callers
 * cannot bypass.
 */
final class AgentPictureNotOwnedException extends RuntimeException
{
    public static function forAsset(int $subjectId, string $assetId, string $subjectLabel = 'agent'): self
    {
        return new self(sprintf(
            "Media asset '%s' is not owned by the user that owns %s %d.",
            $assetId,
            $subjectLabel,
            $subjectId,
        ));
    }
}
