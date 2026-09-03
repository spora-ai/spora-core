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

/**
 * Build a WorkerQueueProcessor with no real workers behind it — the
 * Orchestrator/Mercure/Notification collaborators are silenced mocks because
 * Track 3's tests only exercise {@see WorkerQueueProcessor::spawnChild()} +
 * {@see WorkerQueueProcessor::reapChildren()} which never touch them.
 *
 * The {@code cmdFactory} closure lets a test substitute a controlled PHP
 * child (`php -r 'echo "hi"; exit 0;'`) for the real `bin/spora task:run`
 * boot path that would otherwise need a Spora kernel in scope.
 *
 * @param Closure(int): list<string> $cmdFactory
 */
function makeChildCaptureProcessor(LoggerInterface $logger, Closure $cmdFactory): WorkerQueueProcessor
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
 * Capture-logger helper for tests. Returns a PSR-3 logger plus the
 * TestHandler that backs it, so assertions can inspect {@code records}
 * with the same shape Pest/PHPUnit expect.
 *
 * @return array{0: LoggerInterface, 1: TestHandler}
 */
function makeCapturingLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);
    return [$logger, $handler];
}

/**
 * Look up the first log record matching {@code $message} from a Monolog
 * TestHandler. Returns the record (Monolog\LogRecord) or null.
 */
function findRecord(TestHandler $handler, string $message): ?Monolog\LogRecord
{
    foreach ($handler->getRecords() as $record) {
        if ($record['message'] === $message) {
            return $record;
        }
    }
    return null;
}

describe('WorkerQueueProcessor — child stdio capture + reap log', function (): void {
    it('captures child stdout and stderr and logs them on reap', function (): void {
        // PHP one-liner that writes a marker to each pipe and exits 0 so
        // we exercise the success branch (info level) of `child_exit`.
        [$logger, $handler] = makeCapturingLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "OUT_OK\n"); fwrite(STDERR, "ERR_OK\n"); exit(0);',
        ];

        $processor = makeChildCaptureProcessor($logger, $cmdFactory);

        $pid = $processor->spawnChild(42);
        expect($pid)->toBeInt();

        // Poll for child completion — the small one-liner exits quickly,
        // but PHP's proc scheduling is non-zero so we may need a second
        // reap sweep after a short wait.
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && findRecord($handler, 'child_exit') === null) {
            $processor->reapChildren();
            usleep(20_000);
        }

        $record = findRecord($handler, 'child_exit');
        expect($record)->not->toBeNull();

        $context = $record->context;
        expect($context['task_id'])->toBe(42)
            ->and($context['exit_code'])->toBe(0)
            ->and($context['stdout_excerpt'])->toContain('OUT_OK')
            ->and($context['stderr_excerpt'])->toContain('ERR_OK');

        // Success path emits info, not error.
        expect($record->level)->toBe(Monolog\Level::Info);
    });

    it('truncates stdout beyond the 64KB pipe budget with a marker', function (): void {
        // Write 200 KB to stdout so we must exceed the 65 536-byte budget
        // by a comfortable margin — 200 KB is roughly three times over.
        [$logger, $handler] = makeCapturingLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'echo str_repeat("x", 200000); exit(0);',
        ];

        $processor = makeChildCaptureProcessor($logger, $cmdFactory);
        $processor->spawnChild(1);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && findRecord($handler, 'child_exit') === null) {
            $processor->reapChildren();
            usleep(50_000);
        }

        $record = findRecord($handler, 'child_exit');
        expect($record)->not->toBeNull();

        $excerpt = $record->context['stdout_excerpt'];
        // Marker must appear and the excerpt must not be the full 200 KB.
        expect($excerpt)->toContain('[...truncated...]')
            ->and(strlen($excerpt))->toBeLessThanOrEqual(65_536);
    });

    it('logs child_exit at error level for non-zero exit codes with structured context', function (): void {
        // PHP one-liner that writes to stderr and exits 1 — must surface
        // at error level so the failure is loud in `docker logs`.
        [$logger, $handler] = makeCapturingLogger();
        $cmdFactory = static fn(int $taskId): array => [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "fatal: kaboom\n"); exit(1);',
        ];

        $processor = makeChildCaptureProcessor($logger, $cmdFactory);
        $processor->spawnChild(7);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && findRecord($handler, 'child_exit') === null) {
            $processor->reapChildren();
            usleep(20_000);
        }

        $record = findRecord($handler, 'child_exit');
        expect($record)->not->toBeNull();

        $context = $record->context;

        expect($record->level)->toBe(Monolog\Level::Error)
            ->and($context['task_id'])->toBe(7)
            ->and($context['exit_code'])->toBe(1)
            ->and($context['signal'])->toBe(0)
            ->and($context['stderr_excerpt'])->toContain('fatal: kaboom');

        // Defence-in-depth: every documented context key is present so
        // operators don't hit "undefined index" when piping to jq.
        foreach (['pid', 'exit_code', 'signal', 'task_id', 'stdout_excerpt', 'stderr_excerpt'] as $key) {
            expect($context)->toHaveKey($key);
        }
    });

    it('aborts gracefully when proc_open fails and the worker does not crash', function (): void {
        // A path that doesn't exist (and isn't executable) is the most
        // portable way to make proc_open return false on Linux/macOS.
        // The processor must catch the failure, log it, and either
        // return null (proc_open refused) or a pid (proc_open accepted
        // argv and the child later exited non-zero) — neither is a
        // crash, and either way a log line must surface.
        [$logger, $handler] = makeCapturingLogger();
        $cmdFactory = static fn(int $taskId): array => [
            '/this/binary/absolutely/does/not/exist/anywhere',
            '--',
            (string) $taskId,
        ];

        $processor = makeChildCaptureProcessor($logger, $cmdFactory);

        $pid = null;
        try {
            $pid = $processor->spawnChild(99);
        } catch (Throwable $e) {
            // proc_open may throw ValueError on some PHP versions; that's
            // a different shape of failure than returning false, but
            // either way the worker must not see a fatal error bubble up.
            $processor = makeChildCaptureProcessor($logger, $cmdFactory);
            $pid = $processor->spawnChild(99);
        }

        // Either null (proc_open returned false) or a pid (proc_open
        // accepted the argv and the child later exited non-zero) is
        // acceptable. The contract being tested is "doesn't crash".
        expect($pid === null || $pid > 0)->toBeTrue();

        // If we got a pid, the reap must also not throw and must surface
        // a (possibly error-level) child_exit entry.
        if ($pid !== null) {
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline && findRecord($handler, 'child_exit') === null) {
                $processor->reapChildren();
                usleep(20_000);
            }
        }

        // At minimum a child_exit line (or a "Failed to spawn" error line)
        // must be present — never silence.
        $childExit = findRecord($handler, 'child_exit');
        $spawnFail = findRecord($handler, 'Failed to spawn child process');
        expect($childExit !== null || $spawnFail !== null)->toBeTrue();
    });
});
