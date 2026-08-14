<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Task;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * SQL-side writer for task status transitions. Lives outside
 * {@see Orchestrator} so the orchestration class stays focused on
 * policy / state-machine concerns and the row-update shapes (continuation
 * fields, aborted_at stamp, status flip) are centralised here in one
 * place. Without this, every new transition would either duplicate the
 * UPDATE block or grow the orchestrator's method count past the
 * reviewer's threshold.
 *
 * Caller contract: methods on this class assume the caller already
 * holds `lockForUpdate()` on the target task row, and is inside the
 * open {@see Capsule::connection()->transaction()} if composition is
 * required. The class performs no transactional bookkeeping of its
 * own.
 */
final class TaskStatusWriter
{
    /**
     * Apply a status flip on $task while persisting the new user_prompt,
     * max_steps, and a step_count reset. Called by every branch of
     * `Orchestrator::continue()` — the RUNNING→ABORTED auto-abort, the
     * ABORTED→RUNNING/QUEUED resume, and the COMPLETED/FAILED→RUNNING/QUEUED
     * follow-ups.
     *
     * Continuing from a previously-failed task clears the retry chain
     * markers (`retry_of_task_id`, `retry_after`) and the failure
     * columns — same contract as {@see Orchestrator::retry()} so a
     * continue-press after a failure doesn't get stranded in
     * QUEUED by the worker's `retry_of_task_id IS NULL` claim
     * predicate. The previous status (`RUNNING`, `ABORTED`,
     * `COMPLETED`, `FAILED`) is otherwise preserved through the
     * transition.
     *
     * @param bool $clearAbortedAt true on ABORTED → RUNNING so the
     *                             column is wiped before the next loop
     */
    public function applyContinueTransition(
        Task $task,
        string $newPrompt,
        ?int $additionalSteps,
        string $targetStatus,
        bool $clearAbortedAt,
    ): Task {
        $data = is_array($task->data) ? $task->data : [];

        if ($targetStatus === 'ABORTED') {
            $data['aborted_at'] = gmdate(Orchestrator::DB_TIMESTAMP_FORMAT);
        } elseif ($clearAbortedAt) {
            unset($data['aborted_at']);
        }

        // Drop the auto-retry chain markers and the failure columns —
        // mirrors Orchestrator::retry() so an aborted → continued task
        // is claimable by the main worker loop (`retry_of_task_id IS
        // NULL` predicate) and stops presenting as failed on the
        // dashboard. retry_count is preserved so the throttle still
        // remembers how many attempts have been used.
        // Empty array → JSON null so the column is rewritten (clearing
        // any leftover keys) instead of silently leaving the old payload
        // in place.
        $this->writeTransition($task, $targetStatus, $data, [
            'step_count'       => 0,
            'user_prompt'      => Utf8Sanitizer::scrubString($newPrompt),
            'max_steps'        => $additionalSteps !== null ? $additionalSteps : $task->max_steps,
            'retry_of_task_id' => null,
            'retry_after'      => null,
            'error_code'       => null,
            'error_message'    => null,
            'failure_reason'   => null,
        ]);

        return Task::find($task->id);
    }

    /**
     * Stamp `data.aborted_at` + flip status to ABORTED in a single UPDATE
     * so the {@see Orchestrator::abort()} path doesn't duplicate the
     * row-update SQL. Caller is responsible for the lockForUpdate
     * transaction.
     */
    public function abortTransition(Task $task): void
    {
        $data = is_array($task->data) ? $task->data : [];
        $data['aborted_at'] = gmdate(Orchestrator::DB_TIMESTAMP_FORMAT);
        $this->writeTransition($task, 'ABORTED', $data);
    }

    /**
     * Persist a task's new status + updated-at stamp + data column in one
     * place. Used by both {@see applyContinueTransition()} (which adds
     * prompt / max_steps / step_count on top) and {@see abortTransition()}
     * (status-only flip with aborted_at). Centralising the row layout
     * keeps the SQL UPDATE site small enough that a future column
     * doesn't double-edit half a dozen call sites.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extraColumns
     */
    private function writeTransition(Task $task, string $targetStatus, array $data, array $extraColumns = []): void
    {
        $row = array_merge(
            [
                'status'     => $targetStatus,
                'data'       => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => gmdate(Orchestrator::DB_TIMESTAMP_FORMAT),
            ],
            $extraColumns,
        );
        Capsule::table('tasks')->where('id', $task->id)->update($row);
    }
}
