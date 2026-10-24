<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Agents\Exceptions\CompactionFailedException;

/**
 * Compacts a task's history: everything but the last few rows is replaced by
 * a summary for the model.
 *
 * The summarised rows stay in the history — the operator can still read them
 * — so this changes what the model is sent, not what the transcript holds.
 */
interface TaskCompactionServiceInterface
{
    /**
     * @param  int  $taskId  The task whose history is compacted.
     * @return bool  True when a summary was written. A history too short to
     *               summarise, or a task or agent that no longer exists,
     *               leaves the history untouched.
     *
     * @throws CompactionFailedException  When the model returned no summary;
     *                                    the history is left unchanged.
     */
    public function compact(int $taskId): bool;
}
