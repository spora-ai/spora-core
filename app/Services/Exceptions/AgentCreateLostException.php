<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent row that was just inserted cannot be re-read inside
 * the same create-transaction. This indicates either a concurrent delete
 * racing the create, a deleted-on-cascade foreign key, or a driver-level
 * read-after-write inconsistency — none of which are recoverable, so the
 * transaction is rolled back and the caller is told the create failed.
 */
final class AgentCreateLostException extends RuntimeException
{
    public static function forId(int $agentId): self
    {
        return new self("Created agent {$agentId} disappeared mid-create.");
    }
}
