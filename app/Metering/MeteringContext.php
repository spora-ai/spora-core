<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * Who a metered call belongs to.
 *
 * Built from the task row alone. The principal is the owner — a user or a
 * group — and is always the entity a charge lands on; migration 0073 makes
 * `tasks.principal_id` NOT NULL. The trigger user is whoever clicked; it is
 * nullable and carries no ownership weight, which is why a scheduled run has
 * none.
 *
 * `principalType` is deliberately absent: the context costs one query per
 * metered call, and a meter that needs the type resolves it once and
 * caches it — a principal's type never changes.
 */
final readonly class MeteringContext
{
    public function __construct(
        public int $taskId,
        public int $agentId,
        public int $principalId,
        public ?int $triggerUserId,
        public bool $isSystemRun,
    ) {}
}
