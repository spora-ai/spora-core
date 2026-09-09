<?php

declare(strict_types=1);

namespace Spora\Events;

use Psr\Container\ContainerInterface;

/**
 * Dispatched per request, after the container is built and the database
 * has booted. Listeners receive the live container via {@see container()}
 * for stateful init that needs DI services.
 *
 * @example
 * ```php
 * public function onBooting(BootingEvent $event): void {
 *     $logger = $event->container()->get(LoggerInterface::class);
 *     $logger->info('plugin booted');
 * }
 * ```
 */
final class BootingEvent
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function container(): ContainerInterface
    {
        return $this->container;
    }
}
