<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Core\Kernel;
use Spora\Core\OrchestratorContainerBindings;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Search\SearchContext;
use Spora\Search\SearchProviderInterface;
use Spora\Search\SearchProviderRegistry;

/**
 * The `searchProviders()` hook is a **static class list**, read once at
 * container build time. Every property worth pinning here is therefore a fact
 * about the wiring in `OrchestratorContainerBindings` rather than about any one
 * class, so these tests go through a real container.
 *
 * The precedence assertions are deliberately *not* copies of the skills wiring
 * test: core ships no search provider, so `search_provider_classes` is empty
 * and the merged order is plugin load order. A plugin that claims a `type`
 * another installed plugin already serves takes that section, and which one
 * keeps it depends on which plugin loads first — a property no core-side rule
 * can restore.
 */

/** Fixture roots, matching the two plugin directories on disk. */
const SEARCH_FIXTURE_ROOT = BASE_PATH . '/tests/Fixtures/plugins_with_search';
const SEARCH_FIXTURE_ROOT_MORE = BASE_PATH . '/tests/Fixtures/plugins_with_search_more';

/**
 * Fixture classes are named as strings: they live inside the plugins' own
 * PSR-4 roots, which the loader registers from `plugin.json` at boot, so the
 * names are not resolvable to the analyser before a plugin has booted.
 */
const STUB_SEARCH_PROVIDER = 'Tests\Fixtures\Plugins\SearchPlugin\StubSearchProvider';
const RIVAL_SEARCH_PROVIDER = 'Tests\Fixtures\Plugins\SearchPlugin\RivalSearchProvider';
const LATER_SEARCH_PROVIDER = 'Tests\Fixtures\Plugins\SearchPluginTwo\LaterSearchProvider';

function searchPluginLoader(?string $more = null): PluginLoader
{
    $loader = new PluginLoader(array_filter([SEARCH_FIXTURE_ROOT, $more]), null);
    $loader->boot();

    return $loader;
}

/**
 * The orchestrator slice plus a controllable PluginLoader, so a test decides
 * which plugins contribute providers. `$extra` is merged last and therefore
 * wins — which is how the core-list test overrides `search_provider_classes`.
 *
 * @param array<string, mixed> $extra
 */
function makeSearchContainer(?PluginLoader $loader = null, array $extra = []): ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge([
        'config' => static fn(): array => [
            'app_env'  => 'testing',
            'key_path' => null,
        ],
        PluginLoader::class => $loader ?? searchPluginLoader(),
        Paths::class => static fn(): Paths => new Paths(BASE_PATH),
        // The registry factory injects a logger and the orchestrator slice does
        // not define one; the real definition writes to storage/spora.log.
        LoggerInterface::class => static fn(): LoggerInterface => new NullLogger(),
    ], OrchestratorContainerBindings::all(), $extra));

    return $builder->build();
}

/**
 * The provider instances the registry holds, in the order it will consult
 * them — the precedence order, which is plugin load order today.
 *
 * Read off the property rather than through an accessor: an accessor for this
 * had no production caller (the palette reads `type` per hit, from the response
 * body), so the class exposes none and the wiring asserts the order directly.
 *
 * @return list<SearchProviderInterface>
 */
function searchRegistryProviders(SearchProviderRegistry $registry): array
{
    /** @var list<SearchProviderInterface> $providers */
    $providers = (new ReflectionProperty(SearchProviderRegistry::class, 'providers'))->getValue($registry);

    return $providers;
}

it('defaults searchProviders() to an empty list on plugins and extensions', function (): void {
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

    expect($plugin->searchProviders())->toBe([])
        ->and($extension->searchProviders())->toBe([]);
});

it('collects provider class names from loaded plugins, in declaration order', function (): void {
    expect(searchPluginLoader()->searchProviderClasses())
        ->toBe([STUB_SEARCH_PROVIDER, RIVAL_SEARCH_PROVIDER]);
});

it('returns an empty list when no plugins contribute providers', function (): void {
    $loader = new PluginLoader([]);
    $loader->boot();

    expect($loader->searchProviderClasses())->toBe([]);
});

it('core contributes no search provider, so nothing can precede a plugin', function (): void {
    // The release moved skill search out of core. If a core provider ever comes
    // back, this is the assertion that has to be rewritten — and rewritten
    // deliberately, because the class docblock and the precedence rule both
    // depend on the list being empty.
    expect(makeSearchContainer()->get('search_provider_classes'))->toBe([]);
});

it('the merged list is plugin load order, deduped', function (): void {
    // Plugin one declares [Stub, Rival]; plugin two declares [Later, Stub].
    // Discovery is alphabetical, so plugin one loads first and Stub reaches the
    // merge from both sides.
    $loader = searchPluginLoader(SEARCH_FIXTURE_ROOT_MORE);

    expect($loader->searchProviderClasses())
        ->toBe([STUB_SEARCH_PROVIDER, RIVAL_SEARCH_PROVIDER, LATER_SEARCH_PROVIDER, STUB_SEARCH_PROVIDER])
        ->and(makeSearchContainer($loader)->get('search_provider_classes_merged'))
        ->toBe([STUB_SEARCH_PROVIDER, RIVAL_SEARCH_PROVIDER, LATER_SEARCH_PROVIDER]);
});

it('the registry holds one instance per merged class, in merged order', function (): void {
    $loader = searchPluginLoader(SEARCH_FIXTURE_ROOT_MORE);

    $providers = searchRegistryProviders(
        makeSearchContainer($loader)->get(SearchProviderRegistry::class),
    );

    // Without `array_unique` the repeated Stub would be built and consulted
    // twice; the labels below also show the consultation order.
    expect(array_map(static fn(SearchProviderInterface $p): string => $p::class, $providers))
        ->toBe([STUB_SEARCH_PROVIDER, RIVAL_SEARCH_PROVIDER, LATER_SEARCH_PROVIDER]);
});

it('resolves each provider class from the container, not with a bare new', function (): void {
    $c = makeSearchContainer();

    $providers = searchRegistryProviders($c->get(SearchProviderRegistry::class));

    // Identity, not just type: the same instances `$c->get($class)` hands out,
    // so a provider that caches state is the instance the palette talks to.
    expect($providers[0])->toBe($c->get(STUB_SEARCH_PROVIDER))
        ->and($providers[1])->toBe($c->get(RIVAL_SEARCH_PROVIDER));
});

it('autowires a plugin provider that takes constructor arguments', function (): void {
    $c = makeSearchContainer();

    $stub = searchRegistryProviders($c->get(SearchProviderRegistry::class))[0];

    // `StubSearchProvider::__construct(LoggerInterface $logger)` has no
    // definition of its own, so the only way it exists is PHP-DI resolving the
    // argument. The argument is the container's own logger, read reflectively so
    // the assertion does not depend on the fixture class being analysable.
    $injected = (new ReflectionProperty($stub, 'logger'))->getValue($stub);

    expect($injected)->toBe($c->get(LoggerInterface::class));
});

it('a core-listed class would come first and appear once', function (): void {
    // Core's list is empty today, so the only way to pin the shape of the merge
    // itself is to hand it a list: Rival is named both here and by the plugin,
    // and the position it lands in is what says core-before-plugin.
    $c = makeSearchContainer(null, ['search_provider_classes' => [RIVAL_SEARCH_PROVIDER]]);

    expect($c->get('search_provider_classes_merged'))
        ->toBe([RIVAL_SEARCH_PROVIDER, STUB_SEARCH_PROVIDER]);
});

it('a collision on type::id is decided by plugin load order', function (): void {
    // All three providers claim `fixture-search::invoice`. Plugin one loads
    // first, so its provider keeps the key and the later plugin's section is
    // dropped — precedence, not core-first, and nothing else decides it.
    $hits = makeSearchContainer(searchPluginLoader(SEARCH_FIXTURE_ROOT_MORE))
        ->get(SearchProviderRegistry::class)
        ->search('invoice', new SearchContext([1]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->label)->toBe('from-stub');
});

it('the loaded providers are actually consulted', function (): void {
    // Guards the search() call above from passing for the wrong reason: a
    // registry holding no providers would also return one hit-less result.
    $registry = makeSearchContainer()->get(SearchProviderRegistry::class);

    $hits = $registry->search('invoice', new SearchContext([1]));

    expect(array_map(static fn($hit): string => $hit->label, $hits))->toBe(['from-stub']);
});

it('the real kernel wires an empty registry, so a plain install has no palette sections', function (): void {
    // No plugin is installed in a checkout, so this is what the ⌘K endpoint
    // holds in production today. It is the observable consequence of core
    // shipping no provider, and the reason shipping one is the plugin's job.
    $providers = searchRegistryProviders((new Kernel())->getContainer()->get(SearchProviderRegistry::class));

    expect($providers)->toBe([]);
});
