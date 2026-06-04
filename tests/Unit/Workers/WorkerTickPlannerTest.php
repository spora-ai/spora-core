<?php

declare(strict_types=1);

use Spora\Workers\WorkerTickPlanner;

describe('WorkerTickPlanner::planQueued', function (): void {

    it('returns an empty list when there are no tasks', function (): void {
        expect(WorkerTickPlanner::planQueued([], 0, 0))->toBe([]);
    });

    it('returns all tasks when there are fewer than maxConcurrent', function (): void {
        $tasks = [['id' => 1], ['id' => 2]];
        $result = WorkerTickPlanner::planQueued($tasks, 5, 0);
        expect($result)->toBe($tasks);
    });

    it('returns all tasks when count equals maxConcurrent', function (): void {
        $tasks = [['id' => 1], ['id' => 2], ['id' => 3]];
        $result = WorkerTickPlanner::planQueued($tasks, 3, 0);
        expect($result)->toBe($tasks);
    });

    it('caps to maxConcurrent when there are more tasks', function (): void {
        $tasks = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
        $result = WorkerTickPlanner::planQueued($tasks, 2, 0);
        expect($result)->toBe([['id' => 1], ['id' => 2]]);
    });

    it('treats maxConcurrent=0 as unlimited', function (): void {
        $tasks = [['id' => 1], ['id' => 2], ['id' => 3]];
        expect(WorkerTickPlanner::planQueued($tasks, 0, 0))->toBe($tasks);
    });

    it('applies the per-tick limit cap', function (): void {
        $tasks = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
        $result = WorkerTickPlanner::planQueued($tasks, 0, 2);
        expect($result)->toBe([['id' => 1], ['id' => 2]]);
    });

    it('applies both caps — maxConcurrent first, then limit', function (): void {
        $tasks = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];
        // maxConcurrent=4 → [1,2,3,4], then limit=2 → [1,2]
        $result = WorkerTickPlanner::planQueued($tasks, 4, 2);
        expect($result)->toBe([['id' => 1], ['id' => 2]]);
    });
});

describe('WorkerTickPlanner::isOrphan', function (): void {

    beforeEach(function (): void {
        $this->now = new DateTimeImmutable('2025-01-01 12:00:00', new DateTimeZone('UTC'));
    });

    it('returns false when max age is zero (reaper disabled)', function (): void {
        $now = $this->now;
        $task = ['status' => 'RUNNING', 'updated_at' => '2020-01-01 00:00:00'];
        expect(WorkerTickPlanner::isOrphan($task, 0, $now))->toBeFalse();
    });

    it('returns false when task is fresh', function (): void {
        $now = $this->now;
        $recent = $now->modify('-30 seconds')->format('Y-m-d H:i:s');
        $task = ['status' => 'RUNNING', 'updated_at' => $recent];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeFalse();
    });

    it('returns true when RUNNING and older than threshold', function (): void {
        $now = $this->now;
        $old = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        $task = ['status' => 'RUNNING', 'updated_at' => $old];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeTrue();
    });

    it('returns false for terminal states even when old', function (): void {
        $now = $this->now;
        $old = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        foreach (['COMPLETED', 'FAILED', 'CANCELLED'] as $status) {
            $task = ['status' => $status, 'updated_at' => $old];
            expect(WorkerTickPlanner::isOrphan($task, 60, $now))
                ->toBeFalse("terminal status {$status} must not be orphan");
        }
    });

    it('returns false for QUEUED tasks even when old', function (): void {
        $now = $this->now;
        $old = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        $task = ['status' => 'QUEUED', 'updated_at' => $old];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeFalse();
    });

    it('treats updated_at exactly at the threshold as orphan', function (): void {
        $now = $this->now;
        $exact = $now->modify('-60 seconds')->format('Y-m-d H:i:s');
        $task = ['status' => 'RUNNING', 'updated_at' => $exact];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeTrue();
    });

    it('returns false when updated_at is missing', function (): void {
        $now = $this->now;
        $task = ['status' => 'RUNNING'];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeFalse();
    });

    it('accepts DateTimeImmutable updated_at', function (): void {
        $now = $this->now;
        $old = $now->modify('-5 minutes');
        $task = ['status' => 'RUNNING', 'updated_at' => $old];
        expect(WorkerTickPlanner::isOrphan($task, 60, $now))->toBeTrue();
    });
});

describe('WorkerTickPlanner::isScheduledDue', function (): void {

    beforeEach(function (): void {
        $this->now = new DateTimeImmutable('2025-01-01 12:00:00', new DateTimeZone('UTC'));
    });

    it('returns true when the schedule has never run', function (): void {
        $now = $this->now;
        expect(WorkerTickPlanner::isScheduledDue(null, '0 * * * *', $now))->toBeTrue();
    });

    it('returns false when the schedule ran recently', function (): void {
        $now = $this->now;
        // Every 3 days at midnight. With $now=12:00 and $last=5min ago (11:55),
        // the next occurrence is 3 days away — not due.
        $last = $now->modify('-5 minutes');
        expect(WorkerTickPlanner::isScheduledDue($last, '0 0 */3 * *', $now))->toBeFalse();
    });

    it('returns true when the next cron occurrence is at or before now', function (): void {
        $now = $this->now;
        $last = $now->modify('-2 hours');
        // The next run after 2 hours ago, with hourly cron, is an hour ago — due.
        expect(WorkerTickPlanner::isScheduledDue($last, '0 * * * *', $now))->toBeTrue();
    });

    it('returns false for an invalid cron expression', function (): void {
        $now = $this->now;
        $last = $now->modify('-1 hour');
        expect(WorkerTickPlanner::isScheduledDue($last, 'not-a-cron', $now))->toBeFalse();
    });

    it('returns true for a never-run entry, even with a syntactically odd cron (caller validates cron at config time)', function (): void {
        $now = $this->now;
        // Never-run is unconditionally true; cron validity is the caller's responsibility.
        expect(WorkerTickPlanner::isScheduledDue(null, 'definitely not cron', $now))->toBeTrue();
    });
});
