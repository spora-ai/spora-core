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
 * Build a WorkerQueueProcessor with collaborators silenced via
 * {@code shouldIgnoreMissing()}; this suite only exercises the spawn /
 * reap path that the original {@see WorkerQueueProcessorChildCaptureTest}
 * already covers. The {@code cmdFactory} closure substitutes a controlled
 * PHP one-liner for the real `bin/spora task:run` so we don't need a
 * booted Spora child to assert on the pid-keyed accumulator.
 *
 * @param Closure(int): list<string> $cmdFactory
 */
function makeChildStreamsKeyProcessor(LoggerInterface $logger, Closure $cmdFactory): WorkerQueueProcessor
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
 * from the sibling capture suite so the two files read consistently.
 *
 * @return array{0: LoggerInterface, 1: TestHandler}
 */
function makeChildStreamsKeyLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);
    return [$logger, $handler];
}

/**
 * Collect every {@code child_exit} record for {@code $pid}. Returns an
 * indexed array (possibly empty) so a {@code toHaveCount(1)} assertion
 * gives a useful diff message when the wrong-pid read shows up.
 *
 * @return list<Monolog\LogRecord>
 */
function childExitRecordsForPid(TestHandler $handler, int $pid): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        static fn(Monolog\LogRecord $r): bool => $r->message === 'child_exit'
            && ($r->context['pid'] ?? null) === $pid,
    ));
}

describe('WorkerQueueProcessor — childStreams keyed by pid (no cross-child contamination)', function (): void {
    it('attributes each child\'s stdout to its own pid slot when reaped concurrently', function (): void {
        // Two children emit a unique marker each, then exit 0. If the
        // reaper ever re-keyed on pipe resource id (the pre-fix shape), a
        // resource id recycled after the first child closed its end would
        // let the second child's bytes land in the first slot — and the
        // child_exit log line for the first pid would carry the second's
        // marker. With pid-keyed childStreams (the post-fix shape), each
        // child's bytes go into its own slot and the excerpt stays clean.
        [$logger, $handler] = makeChildStreamsKeyLogger();
        $cmdFactory = static function (int $taskId): array {
            $marker = sprintf('CHILD_%d_OUT_MARKER', $taskId);
            return [
                PHP_BINARY,
                '-r',
                sprintf(
                    'fwrite(STDOUT, "%s\n"); exit(0);',
                    $marker,
                ),
            ];
        };

        $processor = makeChildStreamsKeyProcessor($logger, $cmdFactory);

        $pid1 = $processor->spawnChild(11);
        $pid2 = $processor->spawnChild(22);
        expect($pid1)->toBeInt()
            ->and($pid2)->toBeInt()
            ->and($pid1)->not->toBe($pid2);

        // Drain until both pids have shown up in the child_exit record.
        // Two children, two exits — the loop bound is small.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $processor->reapChildren();
            usleep(20_000);
            if (childExitRecordsForPid($handler, $pid1) !== []
                && childExitRecordsForPid($handler, $pid2) !== []) {
                break;
            }
        }

        $records1 = childExitRecordsForPid($handler, $pid1);
        $records2 = childExitRecordsForPid($handler, $pid2);
        expect($records1)->toHaveCount(1)
            ->and($records2)->toHaveCount(1);

        // Each child's stdout_excerpt must contain only its own marker.
        // Cross-contamination would show up as the sibling marker in the
        // wrong record's stdout_excerpt.
        $excerpt1 = $records1[0]->context['stdout_excerpt'];
        $excerpt2 = $records2[0]->context['stdout_excerpt'];
        expect($excerpt1)->toContain('CHILD_11_OUT_MARKER')
            ->and($excerpt1)->not->toContain('CHILD_22_OUT_MARKER');
        expect($excerpt2)->toContain('CHILD_22_OUT_MARKER')
            ->and($excerpt2)->not->toContain('CHILD_11_OUT_MARKER');
    });
});
