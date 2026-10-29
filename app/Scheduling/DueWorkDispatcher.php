<?php

declare(strict_types=1);

namespace Spora\Scheduling;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the work the registered providers say is due.
 *
 * The two dispatch points — the server worker and the browser's housekeeping
 * call — differ only in the principals they may act for. Everything else is
 * here: one provider's failure must not take the others with it, and a
 * failure inside one unit of work must not abandon the units behind it.
 *
 * A provider that throws while *reporting* its work contributes nothing and
 * is logged. A provider that throws while *executing* a unit leaves that unit
 * unexecuted — the provider owns its rows and decides what an unexecuted unit
 * means — but the remaining units still run.
 */
final class DueWorkDispatcher
{
    public function __construct(
        private readonly DueWorkProviderRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Claim and execute everything due for the given principals.
     *
     * @param list<int> $principalIds principals the caller may act for
     * @return int the number of units executed
     */
    public function dispatch(array $principalIds): int
    {
        $work = $this->collect($principalIds);

        // Oldest first: a slot that came due before the one behind it is the
        // one an operator has been waiting for.
        usort(
            $work,
            static fn(ReportedDueWork $a, ReportedDueWork $b): int => strcmp($a->work->dueAt, $b->work->dueAt),
        );

        $executed = 0;
        foreach ($work as $unit) {
            if ($this->execute($unit)) {
                $executed++;
            }
        }

        return $executed;
    }

    /**
     * @param list<int> $principalIds
     * @return list<ReportedDueWork>
     */
    private function collect(array $principalIds): array
    {
        $allowed = array_flip($principalIds);
        $work = [];

        foreach ($this->registry->all() as $provider) {
            try {
                $reported = $provider->dueWork($principalIds);
            } catch (Throwable $e) {
                $this->logger->error('Due-work provider failed to report its work', [
                    'provider'  => $provider::class,
                    'exception' => $e,
                ]);

                continue;
            }

            foreach ($reported as $unit) {
                // The provider was told which principals it may act for, so
                // this should never fire. It is here because a request must
                // never reach work belonging to a principal the caller cannot
                // act for, and that has to hold even for a provider that
                // ignores the list it was given.
                //
                // At ERROR, not at warning: a unit the provider had already
                // claimed is lost work, and only the provider knows what an
                // abandoned claim means.
                if (!isset($allowed[$unit->principalId])) {
                    $this->logger->error('Due work outside the caller scope was discarded', [
                        'provider'     => $provider::class,
                        'principal_id' => $unit->principalId,
                    ]);

                    continue;
                }

                // The class name is the provider's own spelling of itself.
                // Core routes on the instance that reported the unit, so a
                // wrong name costs nothing but the log line below.
                if ($unit->provider !== $provider::class) {
                    $this->logger->warning('Due work names another provider than the one that reported it', [
                        'provider'       => $provider::class,
                        'named_provider' => $unit->provider,
                    ]);
                }

                $work[] = new ReportedDueWork($provider, $unit);
            }
        }

        return $work;
    }

    private function execute(ReportedDueWork $reported): bool
    {
        try {
            $reported->provider->execute($reported->work);
        } catch (Throwable $e) {
            $this->logger->error('Due work failed to execute', [
                'provider'     => $reported->provider::class,
                'principal_id' => $reported->work->principalId,
                'exception'    => $e,
            ]);

            return false;
        }

        return true;
    }
}
