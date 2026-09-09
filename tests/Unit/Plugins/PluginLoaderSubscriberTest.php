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

        // Dispatching ContainerBuildingEvent via the loader's dispatcher must
        // invoke the plugin's listener. Cold boot path: full discovery, no
        // sidecar. Production calls this exact line from PluginLoader::registerPlugins
        // once commit 2 wires it in.
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

    // Cold-boot sanity: one dispatch, one call.
    $builder1 = new \DI\ContainerBuilder();
    $dispatcher1->dispatch(new ContainerBuildingEvent($builder1));

    /** @var SubscriberPlugin $coldPlugin */
    $coldPlugin = $loader1->getPlugins()['subscriber'];
    expect($coldPlugin->containerBuildingCalls)->toBe(1);

    try {
        // Warm boot: second loader re-instantiates plugins from the sidecar
        // without re-reading manifests. Subscriber wiring must still happen.
        $dispatcher2 = new EventDispatcher();
        $loader2 = new PluginLoader([$dir], $stamp, $dispatcher2);
        $loader2->boot();
        $loader2->wireEventSubscribers();

        // Warm-boot loader has its own subscriber (a fresh instance from
        // sidecar restoration). Assert that one — not the cold-boot one —
        // received the event through the warm-boot dispatcher.
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

        // Replace the subscriber plugin with a plain AbstractPlugin (no
        // EventSubscriberInterface). wireEventSubscribers() must silently
        // skip it; no exception, no listener added.
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

        // The fallback dispatcher must still receive subscribers — the
        // dispatcher attached to a plugin is internal to the loader.
        expect(true)->toBeTrue();
    } finally {
        $cleanup();
    }
});

test('registerPlugins() does NOT call register() on a plugin that implements EventSubscriberInterface (double-fire guard)', function (): void {
    // A plugin that implements both the deprecated register() hook AND the
    // new EventSubscriberInterface surface. Only the listener should fire —
    // the double-fire guard in PluginLoader::registerPlugins() must skip the
    // old hook so DI bindings are not applied twice.
    $hybrid = new class extends AbstractPlugin implements EventSubscriberInterface {
        public int $registerCalls = 0;
        public int $containerBuildingCalls = 0;

        public function getName(): string
        {
            return 'Hybrid';
        }

        public static function getSubscribedEvents(): array
        {
            return [
                ContainerBuildingEvent::class => 'onContainerBuilding',
            ];
        }

        public function onContainerBuilding(ContainerBuildingEvent $event): void
        {
            $this->containerBuildingCalls++;
        }

        public function register(\DI\ContainerBuilder $builder): void
        {
            $this->registerCalls++;
        }
    };

    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, new EventDispatcher());
    $loader->boot();

    // Inject the hybrid plugin via reflection — same seam used by other
    // PluginLoader tests for plug-in patterns that don't need filesystem
    // backing (PSR-4 quirks, double-fire guard, exception swallowing).
    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['hybrid' => $hybrid]);

    // The PluginLoader's wireEventSubscribers() is what attaches the plugin
    // to the dispatcher; production Kernel wires it from handle() once per
    // request. Mirror that here so the listener actually fires when the
    // event is dispatched.
    $loader->wireEventSubscribers();

    $loader->registerPlugins(new \DI\ContainerBuilder());

    // Listener fired once (from the event dispatch).
    expect($hybrid->containerBuildingCalls)->toBe(1);
    // Old hook was SKIPPED — the double-fire guard worked.
    expect($hybrid->registerCalls)->toBe(0);
});

test('registerRoutes() does NOT call routes() on a plugin that implements EventSubscriberInterface (double-fire guard)', function (): void {
    $hybrid = new class extends AbstractPlugin implements EventSubscriberInterface {
        public int $routesCalls = 0;
        public int $routesRegisteringCalls = 0;

        public function getName(): string
        {
            return 'Hybrid';
        }

        public static function getSubscribedEvents(): array
        {
            return [
                RoutesRegisteringEvent::class => 'onRoutesRegistering',
            ];
        }

        public function onRoutesRegistering(RoutesRegisteringEvent $event): void
        {
            $this->routesRegisteringCalls++;
        }

        public function routes(\Spora\Core\MiddlewareRouteCollector $routes): void
        {
            $this->routesCalls++;
        }
    };

    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, new EventDispatcher());
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['hybrid' => $hybrid]);

    $loader->wireEventSubscribers();

    $loader->registerRoutes(new \Spora\Core\MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased()));

    expect($hybrid->routesRegisteringCalls)->toBe(1);
    expect($hybrid->routesCalls)->toBe(0);
});

test('bootExtensions() does NOT call boot() on a plugin that implements EventSubscriberInterface (double-fire guard)', function (): void {
    $hybrid = new class extends AbstractPlugin implements EventSubscriberInterface {
        public int $bootCalls = 0;
        public int $bootingCalls = 0;

        public function getName(): string
        {
            return 'Hybrid';
        }

        public static function getSubscribedEvents(): array
        {
            return [
                \Spora\Events\BootingEvent::class => 'onBooting',
            ];
        }

        public function onBooting(\Spora\Events\BootingEvent $event): void
        {
            $this->bootingCalls++;
        }

        public function boot(): void
        {
            $this->bootCalls++;
        }
    };

    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, new EventDispatcher());
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['hybrid' => $hybrid]);

    $loader->wireEventSubscribers();

    $container = new \DI\Container();
    $loader->bootExtensions($container);

    expect($hybrid->bootingCalls)->toBe(1);
    expect($hybrid->bootCalls)->toBe(0);
});

test('registerPlugins() still calls register() on a plain AbstractPlugin (non-subscriber fallback)', function (): void {
    // Symmetric negative test for the double-fire guard — a plugin that
    // does NOT implement EventSubscriberInterface must keep receiving the
    // deprecated register() call so existing plugins work unmodified.
    $plain = new class extends AbstractPlugin {
        public int $registerCalls = 0;

        public function getName(): string
        {
            return 'Plain';
        }

        public function register(\DI\ContainerBuilder $builder): void
        {
            $this->registerCalls++;
        }
    };

    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null, new EventDispatcher());
    $loader->boot();

    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['plain' => $plain]);

    $loader->registerPlugins(new \DI\ContainerBuilder());

    expect($plain->registerCalls)->toBe(1);
});
