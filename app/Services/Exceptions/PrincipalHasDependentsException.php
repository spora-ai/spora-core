<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a group-targeted destructive operation (delete group, remove
 * member who owns agents, etc.) is blocked because the principal still owns
 * resources.
 *
 * The two kinds of dependent are distinct resources with distinct
 * remediations, so they travel as distinct payloads: {@see $agentIds} is
 * answered with a `reassign_endpoint` hint, {@see $folderIds} with a
 * `folders_endpoint` hint. A single payload with both would point the
 * operator at a transfer endpoint that cannot unblock a folder.
 */
final class PrincipalHasDependentsException extends RuntimeException
{
    /**
     * @param  list<int>    $agentIds  Agents that would become orphaned
     * @param  list<string> $folderIds Media folders the principal owns
     */
    public function __construct(
        string $message,
        public readonly array $agentIds = [],
        public readonly array $folderIds = [],
    ) {
        parent::__construct($message);
    }
}
