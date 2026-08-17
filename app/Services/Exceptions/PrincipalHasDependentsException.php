<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a group-targeted destructive operation (delete group, remove
 * member who owns agents, etc.) is blocked because the principal still owns
 * resources. The {@see $agentIds} (or analogous) payload lets the controller
 * surface a 409 with a `reassign_endpoint` hint so the operator can
 * transfer the dependents first.
 */
final class PrincipalHasDependentsException extends RuntimeException
{
    /**
     * @param  list<int> $agentIds Agents that would become orphaned
     */
    public function __construct(
        string $message,
        public readonly array $agentIds = [],
    ) {
        parent::__construct($message);
    }
}
