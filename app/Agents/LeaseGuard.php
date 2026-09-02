<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Owns the per-task lease state that TickPhaseRunner extends at every step
 * boundary. Extracted from TickPhaseRunner so the orchestrator loop class
 * stays under the S1448 method-count ceiling; the runtime contract is
 * unchanged.
 */
final class LeaseGuard
{
    private ?string $leaseOwner = null;
    private int $leaseSeconds = 600;

    public function configure(?string $leaseOwner, int $leaseSeconds): void
    {
        $this->leaseOwner = $leaseOwner;
        $this->leaseSeconds = $leaseSeconds;
    }

    public function isActive(): bool
    {
        return $this->leaseOwner !== null;
    }

    public function extend(int $taskId): void
    {
        if ($this->leaseOwner === null) {
            return;
        }

        // A parent in AWAITING_SUB_AGENTS holds a live lease so the
        // sub-agent stall reaper doesn't sweep it before the children
        // finish. WorkerReaper::reapStaleTasks filters by `lease_expires_at`,
        // so extending the lease here keeps the parent visible to the
        // reaper as alive — paired with the new reaper filter on
        // AWAITING_SUB_AGENTS, a parent that genuinely stalls gets reaped
        // on lease expiry, and a healthy multi-child batch holds the row.
        Capsule::table('tasks')
            ->where('id', $taskId)
            ->where('lease_owner', $this->leaseOwner)
            ->whereIn('status', ['RUNNING', 'AWAITING_SUB_AGENTS'])
            ->update([
                'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + $this->leaseSeconds),
            ]);
    }
}
