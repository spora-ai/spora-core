<?php

declare(strict_types=1);

namespace Spora\Scheduling;

/**
 * Holds the {@see DueWorkProviderInterface} instances the worker consults.
 *
 * The list is read once, when the container is built, from the class-list
 * hook {@see \Spora\Extensions\SporaExtensionInterface::dueWorkProviders()} —
 * the same shape as {@see \Spora\Search\SearchProviderRegistry} and
 * {@see \Spora\Speech\SpeechToTextRegistry}. Core ships no provider of its
 * own, so the order is plugin load order.
 */
final readonly class DueWorkProviderRegistry
{
    /** @var list<DueWorkProviderInterface> */
    private array $providers;

    /**
     * @param list<DueWorkProviderInterface> $providers In consultation order.
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * @return list<DueWorkProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
