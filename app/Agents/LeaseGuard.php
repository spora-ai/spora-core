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

        Capsule::table('tasks')
            ->where('id', $taskId)
            ->where('lease_owner', $this->leaseOwner)
            ->where('status', 'RUNNING')
            ->update([
                'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + $this->leaseSeconds),
            ]);
    }
}
