<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Core\OrchestratorContainerBindings;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Scheduling\DueWorkProviderInterface;
use Spora\Scheduling\DueWorkProviderRegistry;

/**
 * The `dueWorkProviders()` hook is a **static class list**, read once at
 * container build time. Every property worth pinning here is therefore a fact
 * about the wiring rather than about any one class, so these tests go through
 * a real container.
 *
 * Core ships no due-work provider, so the merged order is plugin load order.
 */

/** Fixture root, matching the plugin directory on disk. */
const DUE_WORK_FIXTURE_ROOT = BASE_PATH . '/tests/Fixtures/plugins_with_due_work';

/**
 * Fixture classes are named as strings: they live inside the plugin's own
 * PSR-4 root, which the loader registers from `plugin.json` at boot, so the
 * names are not resolvable to the analyser before a plugin has booted.
 */
const STUB_DUE_WORK_PROVIDER = 'Tests\Fixtures\Plugins\DueWorkPlugin\StubDueWorkProvider';
const SECOND_DUE_WORK_PROVIDER = 'Tests\Fixtures\Plugins\DueWorkPlugin\SecondDueWorkProvider';

function dueWorkPluginLoader(?string $stamp = null): PluginLoader
{
    $loader = new PluginLoader([DUE_WORK_FIXTURE_ROOT], $stamp);
    $loader->boot();

    return $loader;
}

/**
 * The orchestrator slice plus a controllable PluginLoader, so a test decides
 * which plugins contribute providers. `$extra` is merged last and therefore
 * wins — which is how the core-list test overrides `due_work_provider_classes`.
 *
 * @param array<string, mixed> $extra
 */
function makeDueWorkContainer(?PluginLoader $loader = null, array $extra = []): ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge([
        PluginLoader::class => $loader ?? dueWorkPluginLoader(),
        Paths::class => static fn(): Paths => new Paths(BASE_PATH),
        // The registry factory injects a logger and the orchestrator slice does
        // not define one; the real definition writes to storage/spora.log.
        LoggerInterface::class => static fn(): LoggerInterface => new NullLogger(),
    ], OrchestratorContainerBindings::all(), $extra));

    return $builder->build();
}

/**
 * The provider instances the registry holds, in the order it will consult
 * them.
 *
 * @return list<DueWorkProviderInterface>
 */
function dueWorkRegistryProviders(DueWorkProviderRegistry $registry): array
{
    /** @var list<DueWorkProviderInterface> $providers */
    $providers = (new ReflectionProperty(DueWorkProviderRegistry::class, 'providers'))->getValue($registry);

    return $providers;
}

it('defaults dueWorkProviders() to an empty list on plugins and extensions', function (): void {
    // Adding a method to SporaExtensionInterface is a breaking change for every
    // out-of-tree extension, so the abstract bases are what absorb it.
    $plugin = new class extends Spora\Plugins\AbstractPlugin {
        public function getName(): string
        {
            return 'Bare';
        }
    };
    $extension = new class extends Spora\Extensions\AbstractExtension {
        public function getName(): string
        {
            return 'Bare';
        }
    };

    expect($plugin->dueWorkProviders())->toBe([])
        ->and($extension->dueWorkProviders())->toBe([]);
});

it('collects provider class names from loaded plugins, in declaration order', function (): void {
    expect(dueWorkPluginLoader()->providerClasses()->dueWork())
        ->toBe([STUB_DUE_WORK_PROVIDER]);
});

it('returns an empty list when no plugins contribute providers', function (): void {
    $loader = new PluginLoader([]);
    $loader->boot();

    expect($loader->providerClasses()->dueWork())->toBe([]);
});

it('core contributes no due-work provider, so nothing can precede a plugin', function (): void {
    // Core's scheduler fires prompts at agents; a plugin's own schedule is
    // something only the plugin knows about. If a core provider ever comes
    // back, this is the assertion that has to be rewritten.
    expect(makeDueWorkContainer()->get('due_work_provider_classes'))->toBe([]);
});

it('the merged list is plugin load order, deduped', function (): void {
    expect(dueWorkPluginLoader()->providerClasses()->dueWork())
        ->toBe([STUB_DUE_WORK_PROVIDER])
        ->and(makeDueWorkContainer()->get('due_work_provider_classes_merged'))
        ->toBe([STUB_DUE_WORK_PROVIDER]);
});

it('the registry holds one instance per merged class, in merged order', function (): void {
    $providers = dueWorkRegistryProviders(
        makeDueWorkContainer()->get(DueWorkProviderRegistry::class),
    );

    expect(array_map(static fn(DueWorkProviderInterface $p): string => $p::class, $providers))
        ->toBe([STUB_DUE_WORK_PROVIDER]);
});

it('resolves each provider class from the container, not with a bare new', function (): void {
    $c = makeDueWorkContainer();

    $providers = dueWorkRegistryProviders($c->get(DueWorkProviderRegistry::class));

    // Identity, not just type: the same instances `$c->get($class)` hands out.
    expect($providers[0])->toBe($c->get(STUB_DUE_WORK_PROVIDER));
});

it('a core-listed class would come first and appear once', function (): void {
    // Core's list is empty today, so the only way to pin the shape of the
    // merge itself is to hand it a list.
    $c = makeDueWorkContainer(null, ['due_work_provider_classes' => [STUB_DUE_WORK_PROVIDER]]);

    expect($c->get('due_work_provider_classes_merged'))
        ->toBe([STUB_DUE_WORK_PROVIDER]);
});

it('a provider the plugin adds after the sidecar was written is still registered', function (): void {
    // The claim is that the hook needs no cache invalidation. The manifest is
    // untouched, so the stamp stays current and boot() takes the sidecar path
    // rather than re-reading it — only reading the live plugin object
    // survives this.
    $stamp   = sys_get_temp_dir() . '/spora_due_work_added_' . uniqid();
    $sidecar = $stamp . '.cache.json';

    try {
        $cold = dueWorkPluginLoader($stamp);
        expect($cold->providerClasses()->dueWork())->toHaveCount(1);

        expect((string) file_get_contents($sidecar))->not->toContain('SecondDueWorkProvider');

        putenv('SPORA_FIXTURE_DUE_WORK_SECOND_PROVIDER=1');

        $warm = dueWorkPluginLoader($stamp);

        expect($warm->providerClasses()->dueWork())->toHaveCount(2)
            ->and($warm->providerClasses()->dueWork()[1])->toEndWith('\\SecondDueWorkProvider')
            ->and(makeDueWorkContainer($warm)->get('due_work_provider_classes_merged'))
            ->toBe([STUB_DUE_WORK_PROVIDER, SECOND_DUE_WORK_PROVIDER]);
    } finally {
        putenv('SPORA_FIXTURE_DUE_WORK_SECOND_PROVIDER');
        @unlink($sidecar);
        @unlink($stamp);
    }
});
