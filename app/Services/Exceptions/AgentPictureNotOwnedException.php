<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an upload is being attached to an agent's profile picture
 * but the underlying media asset is not owned by the same user as the
 * agent. The HTTP layer guards this today, but the service contract
 * enforces it independently so future internal callers cannot bypass.
 */
final class AgentPictureNotOwnedException extends RuntimeException
{
    public static function forAsset(int $agentId, string $assetId): self
    {
        return new self(sprintf(
            "Media asset '%s' is not owned by the user that owns agent %d.",
            $assetId,
            $agentId,
        ));
    }
}
