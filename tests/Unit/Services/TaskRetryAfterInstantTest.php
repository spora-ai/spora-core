<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Spora\Core\Config;
use Spora\Models\Agent;
use Spora\Models\Notification;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskResourceBuilder;
use Tests\Support\TestCapturingMercure;

/**
 * `retry_after` names one instant, and the surfaces that show it have to
 * show that one.
 *
 * The column is UTC: the scheduler writes it with `gmdate()` and the
 * worker daemon's claim query compares it against `gmdate()`. Eloquent's
 * `datetime` cast reads it back in the process default timezone instead,
 * and `Kernel` never calls `date_default_timezone_set()` — so on a host
 * whose php.ini names a local zone the cast hands out an instant an hour
 * (or more) away from the one the column names.
 *
 * The browser's countdown is a difference between two instants, so an hour
 * of drift is an hour of drift on screen — and the retry fires at the
 * moment the column says, not at the moment the payload says.
 */

/** The instant the column names, in the shape the surfaces emit it. */
const RETRY_AFTER_INSTANT_UTC = '2026-11-16T21:02:15+00:00';

/** The same instant, as the column stores it. */
const RETRY_AFTER_INSTANT_COLUMN = '2026-11-16 21:02:15';

/**
 * A failed task whose retry is pending, with the column holding the UTC
 * string the scheduler writes.
 */
function retryAfterInstantSeedTask(): Task
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register(
        sprintf('retry-instant-%d@example.com', $seq),
        'Password1!',
        'Retry Instant',
    );

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Retry Instant Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'FAILED',
        'user_prompt'     => 'the provider is unreachable',
        'max_steps'       => 10,
        'step_count'      => 1,
    ]);

    // Written through the query builder so the column holds exactly the
    // UTC string — the cast is what has to make sense of it.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => RETRY_AFTER_INSTANT_COLUMN,
    ]);

    return $task->refresh();
}

it('the task payload carries the instant the retry_after column names', function (): void {
    $task = retryAfterInstantSeedTask();

    $original = date_default_timezone_get();

    try {
        // The host the API runs on. `Kernel` never calls
        // `date_default_timezone_set()`, so this is whatever php.ini named.
        date_default_timezone_set('Europe/Berlin');

        $resource = (new TaskResourceBuilder())->taskListResource($task);
    } finally {
        date_default_timezone_set($original);
    }

    // The countdown the browser renders is a difference between two
    // instants, so the payload has to carry the instant the column names —
    // not the same wall clock read in the host's zone.
    expect($resource['retry_after'])->toBe(RETRY_AFTER_INSTANT_UTC);
});

it('the retry notification carries the instant the retry_after column names', function (): void {
    $task = retryAfterInstantSeedTask();

    $service = new NotificationService(
        new TestCapturingMercure(),
        new PrincipalResolver(),
        new Config(),
    );

    $original = date_default_timezone_get();

    try {
        date_default_timezone_set('Europe/Berlin');

        $service->notifyRetryQueued($task, 1, 3);
    } finally {
        date_default_timezone_set($original);
    }

    $notification = Notification::where('user_id', $task->trigger_user_id)
        ->where('type', 'task_retry_queued')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['retry_after'])->toBe(RETRY_AFTER_INSTANT_UTC);
});

it('reads a stamp that was set on the model rather than stored', function (): void {
    // A model built from an array holds the Carbon itself, not the string
    // the column stores.
    $inMemory = new Task();
    $inMemory->setRawAttributes([
        'id'          => 1,
        'retry_after' => Carbon::parse(RETRY_AFTER_INSTANT_COLUMN, 'UTC'),
    ], true);

    expect($inMemory->retryAfterUtc()?->toIso8601String())->toBe(RETRY_AFTER_INSTANT_UTC);
});

it('names the instant an unsaved assignment carries', function (): void {
    // The model in hand is the truth about the row: an assignment that
    // has not been saved yet is still the value the accessor has to read.
    // The snapshot of the last synchronised state cannot see it.
    $task = retryAfterInstantSeedTask();

    $original = date_default_timezone_get();

    try {
        date_default_timezone_set('Europe/Berlin');

        $task->retry_after = Carbon::parse('2026-12-01 10:00:00', 'UTC');

        expect($task->retryAfterUtc()?->toIso8601String())->toBe('2026-12-01T10:00:00+00:00');
    } finally {
        date_default_timezone_set($original);
    }
});

it('names the instant an assignment carries, whatever timezone that value carries', function (): void {
    // The column is UTC. A value that carries another offset still names
    // one instant, and the accessor has to name that instant — not the
    // wall clock the value was written in.
    $task = retryAfterInstantSeedTask();

    $task->retry_after = Carbon::parse('2026-12-01 11:00:00', 'Europe/Berlin');

    expect($task->retryAfterUtc()?->toIso8601String())->toBe('2026-12-01T10:00:00+00:00');
});

it('sees a retry that was cleared in memory', function (): void {
    // The other half: the assignment says there is no retry pending, and
    // the accessor has to say so too — the snapshot of the last
    // synchronised state still names the old one.
    $task = retryAfterInstantSeedTask();

    $task->retry_after = null;

    expect($task->retryAfterUtc())->toBeNull();
});

it('treats an unreadable stamp as no retry pending', function (): void {
    // A hand-edited row must not break the payload: the answer is "no
    // retry is scheduled", which is what the browser reads it as.
    $corrupt = new Task();
    $corrupt->setRawAttributes([
        'id'          => 1,
        'retry_after' => 'not-a-timestamp',
    ], true);

    expect($corrupt->retryAfterUtc())->toBeNull();
});
