<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Spora\Console\Worker\WorkerQueueProcessor;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * How a child ended, as the worker's `child_exit` record says.
 *
 * The exit code and the terminating signal are two different facts, and
 * the worker has to keep them apart. A child that calls `exit(9)` and a
 * child that is killed with SIGKILL both leave a wait status of 9, so a
 * source that reads the wait status cannot tell them apart — and a child
 * that exited 0 read too early looks like a failure, because the status
 * reports -1 until the child has been reaped.
 *
 * `proc_get_status()` reaps the child, and only then does it say what
 * happened: the exit code for a child that ended normally, the signal and
 * no code for one that was killed.
 */
function makeExitCodeLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);

    return [$logger, $handler];
}

/**
 * The `child_exit` record, or null when the child was never reaped.
 *
 * @return array<string, mixed>|null
 */
function childExitRecord(TestHandler $handler): ?array
{
    foreach ($handler->getRecords() as $record) {
        if ($record['message'] === 'child_exit') {
            return $record['context'];
        }
    }

    return null;
}

/**
 * Reap until the child is done, or the deadline elapses.
 */
function reapUntilChildExit(WorkerQueueProcessor $processor, TestHandler $handler, float $deadlineSeconds = 5.0): ?array
{
    $deadline = microtime(true) + $deadlineSeconds;

    while (microtime(true) < $deadline) {
        $processor->reapChildren(new BufferedOutput());
        $record = childExitRecord($handler);
        if ($record !== null) {
            return $record;
        }
        usleep(20_000);
    }

    return null;
}

/**
 * @param Closure(int): list<string> $cmdFactory
 */
function makeExitCodeProcessor(Psr\Log\LoggerInterface $logger, Closure $cmdFactory): WorkerQueueProcessor
{
    $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    $orchestrator->shouldIgnoreMissing();

    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class);
    $mercure->shouldIgnoreMissing();

    $notification = Mockery::mock(Spora\Services\NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerQueueProcessor(
        orchestrator: $orchestrator,
        logger: $logger,
        mercure: $mercure,
        notificationService: $notification,
        paths: new Spora\Core\Paths(BASE_PATH),
        cmdFactory: $cmdFactory,
    );
}

test('a child that succeeded is logged with the exit code it returned', function (): void {
    [$logger, $handler] = makeExitCodeLogger();
    $processor = makeExitCodeProcessor($logger, static fn(int $taskId): array => [PHP_BINARY, '-r', 'exit(0);']);

    $processor->spawnChild(1);
    $record = reapUntilChildExit($processor, $handler);

    expect($record)->not->toBeNull()
        ->and($record['exit_code'])->toBe(0)
        ->and($record['signal'])->toBe(0);
});

test('a child that failed is logged with the code it returned', function (): void {
    [$logger, $handler] = makeExitCodeLogger();
    $processor = makeExitCodeProcessor($logger, static fn(int $taskId): array => [PHP_BINARY, '-r', 'exit(7);']);

    $processor->spawnChild(2);
    $record = reapUntilChildExit($processor, $handler);

    expect($record)->not->toBeNull()
        ->and($record['exit_code'])->toBe(7)
        ->and($record['signal'])->toBe(0);
});

test('a child that was killed is logged with the signal, not an exit code', function (): void {
    if (!function_exists('posix_kill')) {
        $this->markTestSkipped('posix_kill() requires ext-posix.');
    }

    // The child closes its stdout and then kills itself. The status read
    // before the kill says nothing; read once the child is reaped, it
    // carries the signal and no exit code — which is what tells a killed
    // child apart from one that called `exit(9)`.
    [$logger, $handler] = makeExitCodeLogger();
    $processor = makeExitCodeProcessor($logger, static fn(int $taskId): array => [
        PHP_BINARY,
        '-r',
        // The child closes its stdout and stays alive for a moment, so the
        // reaper reads its status while it is still running — the window
        // in which the status says nothing and only the wait status is
        // left to read.
        'fclose(STDOUT); usleep(200000); posix_kill(getmypid(), 9); usleep(500000);',
    ]);

    $processor->spawnChild(3);
    $record = reapUntilChildExit($processor, $handler);

    expect($record)->not->toBeNull()
        ->and($record['signal'])->toBe(9)
        ->and($record['exit_code'])->toBe(-1);
});
