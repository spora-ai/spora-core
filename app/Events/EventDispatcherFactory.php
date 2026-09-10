<?php

declare(strict_types=1);

namespace Spora\Events;

use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Builds the framework-wide {@see EventDispatcher} that plugins subscribe to.
 *
 * The factory is invoked by {@see \Spora\Core\ContainerDefinitions} when the
 * `'event_dispatcher'` service is first resolved; one dispatcher instance is
 * shared by every service that needs it (the App, the PluginLoader, plugin
 * subscribers).
 */
final class EventDispatcherFactory
{
    public static function create(): EventDispatcher
    {
        return new EventDispatcher();
    }
}
