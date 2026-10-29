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
 * claimed and unexecuted — the provider owns its rows and decides what an
 * unexecuted claim means — but the remaining units still run.
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
        $work = $this->claim($principalIds);

        // Oldest first: a slot that came due before the one behind it is the
        // one an operator has been waiting for.
        usort($work, static fn(DueWork $a, DueWork $b): int => strcmp($a->dueAt, $b->dueAt));

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
     * @return list<DueWork>
     */
    private function claim(array $principalIds): array
    {
        $allowed = array_flip($principalIds);
        $work = [];

        foreach ($this->registry->all() as $provider) {
            try {
                $claimed = $provider->dueWork($principalIds);
            } catch (Throwable $e) {
                $this->logger->error('Due-work provider failed to report its work', [
                    'provider'  => $provider::class,
                    'exception' => $e,
                ]);

                continue;
            }

            foreach ($claimed as $unit) {
                // The provider was told which principals it may act for, so
                // this should never fire. It is here because a request must
                // never reach work belonging to a principal the caller cannot
                // act for, and that has to hold even for a provider that
                // ignores the list it was given.
                if (!isset($allowed[$unit->principalId])) {
                    $this->logger->warning('Due-work provider returned work for a principal outside the caller scope', [
                        'provider'     => $provider::class,
                        'principal_id' => $unit->principalId,
                    ]);

                    continue;
                }

                $work[] = $unit;
            }
        }

        return $work;
    }

    private function execute(DueWork $unit): bool
    {
        $provider = $this->registry->providerFor($unit->provider);
        if ($provider === null) {
            $this->logger->warning('Due work names a provider that is not registered', [
                'provider' => $unit->provider,
            ]);

            return false;
        }

        try {
            $provider->execute($unit);
        } catch (Throwable $e) {
            $this->logger->error('Due work failed to execute', [
                'provider'     => $provider::class,
                'principal_id' => $unit->principalId,
                'exception'    => $e,
            ]);

            return false;
        }

        return true;
    }
}
