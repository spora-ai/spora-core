<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Core\Paths;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Build a WorkerQueueProcessor with collaborators silenced via
 * {@code shouldIgnoreMissing()}; this suite only exercises the spawn /
 * reap echo path that the original
 * {@see WorkerQueueProcessorChildCaptureTest} already covers for the
 * log side. The {@code cmdFactory} closure substitutes a controlled PHP
 * one-liner for the real `bin/spora task:run` so we don't need a booted
 * Spora child to assert on the operator-facing output line.
 *
 * @param Closure(int): list<string> $cmdFactory
 */
function makeParentStatusProcessor(LoggerInterface $logger, Closure $cmdFactory): WorkerQueueProcessor
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->shouldIgnoreMissing();

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->shouldIgnoreMissing();

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerQueueProcessor(
        orchestrator: $orchestrator,
        logger: $logger,
        mercure: $mercure,
        notificationService: $notification,
        paths: new Paths(BASE_PATH),
        cmdFactory: $cmdFactory,
    );
}

/**
 * Test-handler-backed logger factory, mirroring {@see makeCapturingLogger()}
 * from {@see WorkerQueueProcessorChildCaptureTest} so the two files read
 * consistently.
 *
 * @return array{0: LoggerInterface, 1: TestHandler}
 */
function makeParentStatusLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);
    return [$logger, $handler];
}

/**
 * Drain the reaper until {@code child_exit} appears in {@code $handler},
 * or the deadline elapses. Returns the rendered BufferedOutput once,
 * since {@see BufferedOutput::fetch()} returns AND clears the buffer —
 * callers must not call fetch twice.
 */
function drainUntilChildExit(WorkerQueueProcessor $processor, BufferedOutput $output, TestHandler $handler, float $deadlineSeconds = 2.0): string
{
    $deadline = microtime(true) + $deadlineSeconds;
    while (microtime(true) < $deadline) {
        $processor->reapChildren($output);
        if (findChildExitRecord($handler) !== null) {
            return $output->fetch();
        }
        usleep(20_000);
    }
    return $output->fetch();
}

function findChildExitRecord(TestHandler $handler): ?Monolog\LogRecord
{
    foreach ($handler->getRecords() as $record) {
        if ($record['message'] === 'child_exit') {
            return $record;
        }
    }
    return null;
}

describe('WorkerQueueProcessor — parent-side task completion echo', function (): void {
    it('writes "Task X finished with status: <state>" to $output after a successful child exits', function (): void {
        // No DB row exists for this task id, so resolveFinalStatus() falls
        // back to 'UNKNOWN'. The behaviour under test is that the parent
        // emits the line at all — Track 3's pipe capture dropped the
        // pre-PR-#229 stdout-inherited "Task finished" message, and the
        // operator lost visibility into per-task status in a TTY. A
        // successful child that exits 0 should still surface the line
        // even when the DB lookup returns no row, because the worker
        // must keep running if the DB is briefly unavailable.
        [$logger, $handler] = makeParentStatusLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'exit(0);',
        ];

        $processor = makeParentStatusProcessor($logger, $cmdFactory);
        $output = new BufferedOutput();

        $processor->spawnChild(77);
        $rendered = drainUntilChildExit($processor, $output, $handler);

        expect($rendered)
            ->toContain('Task 77 finished with status: ')
            ->toContain('UNKNOWN');
    });

    it('emits the completion line even when the child exits with a non-zero code', function (): void {
        // Non-zero exit means the worker must not silently swallow the
        // line — operators need to see "Task X finished with status:"
        // regardless of exit code so they can grep for failed tasks by
        // the status field (UNKNOWN here is a fallback, not the
        // interesting part of the assertion).
        [$logger, $handler] = makeParentStatusLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "boom\n"); exit(2);',
        ];

        $processor = makeParentStatusProcessor($logger, $cmdFactory);
        $output = new BufferedOutput();

        $processor->spawnChild(123);
        $rendered = drainUntilChildExit($processor, $output, $handler);

        expect($rendered)
            ->toContain('Task 123 finished with status: ')
            ->toContain('UNKNOWN');
    });

    it('does not emit a completion line while the child is still running', function (): void {
        // A slow child (sleeps 1s) must not surface a "finished" line on
        // an early reap sweep — the line is only for reaped children.
        // Polling reapChildren twice within the child's lifetime and
        // asserting neither sweep produced the line guards against a
        // regression where the echo leaks before proc_close.
        [$logger, $handler] = makeParentStatusLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'sleep(1); exit(0);',
        ];

        $processor = makeParentStatusProcessor($logger, $cmdFactory);
        $output = new BufferedOutput();

        $processor->spawnChild(55);
        $processor->reapChildren($output);
        usleep(100_000);
        $processor->reapChildren($output);

        // The child hasn't exited yet, so child_exit is not present
        // and the output must not contain a "finished" line. Reading
        // output before the child dies also lets us prove the line is
        // only emitted post-proc_close.
        expect(findChildExitRecord($handler))->toBeNull()
            ->and($output->fetch())->not->toContain('Task 55 finished');
    });
});
