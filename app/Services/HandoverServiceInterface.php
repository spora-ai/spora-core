<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;
use Spora\Models\Task;

/**
 * Hands the chat from one user-owned task over to another user-owned agent.
 *
 * The source task is marked COMPLETED with a handover note; a new Task is
 * created via {@see OrchestratorInterface::start()} with `parent_task_id`
 * set to the source, so the new chat inherits the conversation lineage.
 *
 * Authorization: the calling user must own both the source task and the
 * target agent. Cross-user handovers are out of scope for v1.
 */
interface HandoverServiceInterface
{
    /**
     * @throws InvalidArgumentException if the source task or target agent
     *                                   is not owned by $userId.
     */
    public function handover(
        int $sourceTaskId,
        int $targetAgentId,
        string $summary,
        int $userId,
    ): Task;
}
