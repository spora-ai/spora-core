<?php

declare(strict_types=1);

namespace Spora\Events;

use DI\ContainerBuilder;

/**
 * Dispatched once per process, after the App's autoload is registered
 * but before the container is built. Listeners mutate the builder via
 * {@see builder()} — the most common use is registering DI bindings
 * the App cannot autowire.
 *
 * @example
 * ```php
 * public function onContainerBuilding(ContainerBuildingEvent $event): void {
 *     $event->builder()->addDefinitions([
 *         MyInterface::class => \DI\autowire(MyImpl::class),
 *     ]);
 * }
 * ```
 */
final class ContainerBuildingEvent
{
    public function __construct(private readonly ContainerBuilder $builder) {}

    public function builder(): ContainerBuilder
    {
        return $this->builder;
    }
}
