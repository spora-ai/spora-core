<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use ReflectionClass;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\PluginLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A plugin that also subscribes to lifecycle events. Used to verify
 * {@see PluginLoader::wireEventSubscribers()} attaches it to the dispatcher
 * on both cold and warm boots.
 */
final class SubscriberPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    public int $containerBuildingCalls = 0;
    public int $routesRegisteringCalls = 0;
    public ?ContainerBuildingEvent $lastContainerBuilding = null;
    public ?RoutesRegisteringEvent $lastRoutesRegistering = null;

    public function getName(): string
    {
        return 'Subscriber';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $this->containerBuildingCalls++;
        $this->lastContainerBuilding = $event;
    }

    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $this->routesRegisteringCalls++;
        $this->lastRoutesRegistering = $event;
    }
}

/**
 * @return array{0: string, 1: callable(): void}
 */
function makeSubscriberPluginDir(): array
{
    $slug = 'subscriber';
    $dir  = sys_get_temp_dir() . '/spora_subscriber_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents(
        $dir . '/' . $slug . '/plugin.json',
        json_encode([
            'slug'  => $slug,
            'class' => SubscriberPlugin::class,
        ]),
    );

    $cleanup = static function () use ($dir, $slug): void {
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    };

    return [$dir, $cleanup];
}

test('wireEventSubscribers() attaches a plugin implementing EventSubscriberInterface to the dispatcher on cold boot', function (): void {
    [$dir, $cleanup] = makeSubscriberPluginDir();
    $dispatcher = new EventDispatcher();

    try {
        $loader = new PluginLoader([$dir], null, $dispatcher);
        $loader->boot();
        $loader->wireEventSubscribers();

        $builder = new \DI\ContainerBuilder();
        $dispatcher->dispatch(new ContainerBuildingEvent($builder));

        /** @var SubscriberPlugin $plugin */
        $plugin = $loader->getPlugins()['subscriber'];
        expect($plugin)->toBeInstanceOf(SubscriberPlugin::class);
        expect($plugin->containerBuildingCalls)->toBe(1);
        expect($plugin->lastContainerBuilding)->toBeInstanceOf(ContainerBuildingEvent::class);
    } finally {
        $cleanup();
    }
});

test('wireEventSubscribers() re-attaches the plugin on warm boot (PluginLoaderCache hit path)', function (): void {
    [$dir, $cleanup] = makeSubscriberPluginDir();
    $stamp    = $dir . '/.stamp';
    $sidecar  = $stamp . '.cache.json';

    $dispatcher1 = new EventDispatcher();
    $loader1 = new PluginLoader([$dir], $stamp, $dispatcher1);
    $loader1->boot();
    $loader1->wireEventSubscribers();

    $builder1 = new \DI\ContainerBuilder();
    $dispatcher1->dispatch(new ContainerBuildingEvent($builder1));

    /** @var SubscriberPlugin $coldPlugin */
    $coldPlugin = $loader1->getPlugins()['subscriber'];
    expect($coldPlugin->containerBuildingCalls)->toBe(1);

    try {
        $dispatcher2 = new EventDispatcher();
        $loader2 = new PluginLoader([$dir], $stamp, $dispatcher2);
        $loader2->boot();
        $loader2->wireEventSubscribers();

        /** @var SubscriberPlugin $warmPlugin */
        $warmPlugin = $loader2->getPlugins()['subscriber'];
        expect($warmPlugin)->not->toBe($coldPlugin);

        $builder2 = new \DI\ContainerBuilder();
        $dispatcher2->dispatch(new ContainerBuildingEvent($builder2));
        expect($warmPlugin->containerBuildingCalls)->toBe(1);
        expect($coldPlugin->containerBuildingCalls)->toBe(1);
    } finally {
        @unlink($sidecar);
        @unlink($stamp);
        $cleanup();
    }
});

test('wireEventSubscribers() is a no-op for plugins that do not implement EventSubscriberInterface', function (): void {
    [$dir, $cleanup] = makeSubscriberPluginDir();
    $dispatcher = new EventDispatcher();

    try {
        $loader = new PluginLoader([$dir], null, $dispatcher);
        $loader->boot();

        $plain = new class extends AbstractPlugin {
            public function getName(): string
            {
                return 'Plain';
            }
        };
        $reflection = new ReflectionClass($loader);
        $pluginsProperty = $reflection->getProperty('plugins');
        $pluginsProperty->setValue($loader, ['subscriber' => $plain]);

        $loader->wireEventSubscribers();
        expect(true)->toBeTrue();
    } finally {
        $cleanup();
    }
});

test('PluginLoader without an explicit dispatcher constructs its own (autowire fallback)', function (): void {
    [$dir, $cleanup] = makeSubscriberPluginDir();

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        $loader->wireEventSubscribers();

        expect(true)->toBeTrue();
    } finally {
        $cleanup();
    }
});

test('registerPlugins() fires ContainerBuildingEvent and the subscriber receives it', function (): void {
    $subscriber = new SubscriberPlugin();

    $dispatcher = new EventDispatcher();
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, $dispatcher);
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['subscriber' => $subscriber]);

    $loader->wireEventSubscribers();

    $loader->registerPlugins(new \DI\ContainerBuilder());

    expect($subscriber->containerBuildingCalls)->toBe(1);
});

test('registerRoutes() fires RoutesRegisteringEvent and the subscriber receives it', function (): void {
    $subscriber = new SubscriberPlugin();

    $dispatcher = new EventDispatcher();
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, $dispatcher);
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['subscriber' => $subscriber]);

    $loader->wireEventSubscribers();

    $loader->registerRoutes(new \Spora\Core\MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased()));

    expect($subscriber->routesRegisteringCalls)->toBe(1);
});

test('bootExtensions() fires BootingEvent and the subscriber receives it', function (): void {
    $subscriber = new class extends AbstractPlugin implements EventSubscriberInterface {
        public int $bootingCalls = 0;
        public function getName(): string
        {
            return 'Booting';
        }
        public static function getSubscribedEvents(): array
        {
            return [\Spora\Events\BootingEvent::class => 'onBooting'];
        }
        public function onBooting(\Spora\Events\BootingEvent $event): void
        {
            $this->bootingCalls++;
        }
    };

    $dispatcher = new EventDispatcher();
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, $dispatcher);
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['booting' => $subscriber]);

    $loader->wireEventSubscribers();

    $container = new \DI\Container();
    $loader->bootExtensions($container);

    expect($subscriber->bootingCalls)->toBe(1);
});

// Regression: when AppLoader and PluginLoader share a dispatcher, every
// lifecycle event must fire exactly once per phase across the two loaders.
// Earlier AppLoader also dispatched the same events on the same dispatcher,
// causing plugin subscribers to receive the event twice — e.g. each plugin
// route was added to the FastRoute collector twice and a duplicate GET
// /api/v1/media/{id} threw BadRouteException at boot.
test('lifecycle events fire exactly once across AppLoader + PluginLoader sharing a dispatcher', function (): void {
    $dispatcher = new EventDispatcher();

    $appLoader = new \Spora\Extensions\AppLoader($dispatcher);
    $pluginLoader = new PluginLoader(
        ['/tmp/spora_no_plugins_' . uniqid()],
        null,
        $dispatcher,
    );
    $pluginLoader->boot();

    $subscriber = new SubscriberPlugin();
    $reflection = new ReflectionClass($pluginLoader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($pluginLoader, ['subscriber' => $subscriber]);

    $pluginLoader->wireEventSubscribers();

    // Phase 1 — container building. AppLoader::load() no longer dispatches
    // (it just instantiates the App). PluginLoader::registerPlugins() is the
    // single dispatch site.
    $builder = new \DI\ContainerBuilder();
    $appLoader->load(
        new \Spora\Core\Paths(sys_get_temp_dir() . '/spora_no_paths_' . uniqid()),
        $builder,
    );
    $pluginLoader->registerPlugins($builder);
    expect($subscriber->containerBuildingCalls)->toBe(1);

    // Phase 2 — routes. AppLoader::registerRoutes() is a no-op; PluginLoader
    // is the single dispatch site. This is the regression — duplicate
    // dispatch here used to throw FastRoute\BadRouteException on duplicate
    // /api/v1/media/{id} GET registrations.
    $routes = new \Spora\Core\MiddlewareRouteCollector(
        new \FastRoute\RouteParser\Std(),
        new \FastRoute\DataGenerator\GroupCountBased(),
    );
    $appLoader->registerRoutes($routes);
    $pluginLoader->registerRoutes($routes);
    expect($subscriber->routesRegisteringCalls)->toBe(1);
});
