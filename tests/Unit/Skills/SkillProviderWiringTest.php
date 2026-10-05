<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Spora\Core\Kernel;
use Spora\Core\OrchestratorContainerBindings;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigNameResolver;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * The `skillProviders()` hook is a **static class list**, not a registry
 * populated from `boot()`. These tests go through a real container because the
 * property that matters — core's provider coming first — is a fact about the
 * wiring, not about any single class in isolation.
 */
function skillsPluginLoader(): PluginLoader
{
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_skills'], null);
    $loader->boot();

    return $loader;
}

/**
 * The orchestrator slice plus a controllable PluginLoader, so a test can decide
 * which plugins contribute providers. The real `Kernel` loads whatever is in
 * the instance's `plugins/` directory, which is not something a test controls.
 *
 * @param list<class-string> $extra Definitions the orchestrator slice needs
 *                                  but does not itself provide.
 */
function makeSkillsContainer(?PluginLoader $loader = null, array $extra = []): ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge([
        'config' => static fn(): array => [
            'app_env'  => 'testing',
            'key_path' => null,
        ],
        PluginLoader::class => $loader ?? skillsPluginLoader(),
        Paths::class => static fn(): Paths => new Paths(BASE_PATH),
    ], $extra, OrchestratorContainerBindings::all()));

    return $builder->build();
}

it('defaults skillProviders() to an empty list on plugins and extensions', function (): void {
    // Adding a method to SporaExtensionInterface is a breaking change for every
    // out-of-tree extension. The abstract bases are what absorb it.
    $plugin = new class extends Spora\Plugins\AbstractPlugin {
        public function getName(): string
        {
            return 'Bare';
        }
    };

    expect($plugin->skillProviders())->toBe([]);
});

it('collects provider class names from loaded plugins', function (): void {
    expect(skillsPluginLoader()->skillProviderClasses())
        ->toBe([StubSkillProvider::class]);
});

it('returns an empty list when no plugins contribute providers', function (): void {
    $loader = new PluginLoader([]);
    $loader->boot();

    expect($loader->skillProviderClasses())->toBe([]);
});

it('the container puts core first and plugin providers after', function (): void {
    $c = makeSkillsContainer();
    $sources = $c->get(SkillProviderRegistry::class)->sources();

    // Core first is the whole point: a plugin that reuses a shipped skill's
    // name must lose, or installing the plugin would silently repoint an
    // existing agent's allowed_skills at different content.
    expect($sources)->toBe(['filesystem', 'stub']);
});

it('the registry resolves each provider class from the same container', function (): void {
    $c = makeSkillsContainer();

    expect($c->get(FilesystemSkillProvider::class))->toBeInstanceOf(SkillProviderInterface::class)
        ->and($c->get(StubSkillProvider::class))->toBeInstanceOf(SkillProviderInterface::class);
});

it('a plugin provider shadows nothing when it reuses a shipped name', function (): void {
    // End-to-end proof of the ordering rule, through the merge: the stub claims
    // `agent-creation`, which ships on disk in this repository.
    $c = makeSkillsContainer();
    $stub = $c->get(StubSkillProvider::class);
    $stub->add('agent-creation', [], 'HIJACKED');

    $summaries = $c->get(SkillProviderRegistry::class)->getSkills(null);
    $names = array_map(static fn($s): string => $s->name, $summaries);

    expect(array_count_values($names)['agent-creation'])->toBe(1)
        ->and($c->get(SkillProviderRegistry::class)->getSkillDetails('agent-creation', null)?->body)
            ->not->toBe('HIJACKED');
});

it('dedupes a class named by two plugins', function (): void {
    $loader = new PluginLoader(
        [BASE_PATH . '/tests/Fixtures/plugins_with_skills', BASE_PATH . '/tests/Fixtures/plugins_with_skills_more'],
        null,
    );
    $loader->boot();

    // Two plugins, one class. Without the unique() the registry would hold the
    // same instance twice and every list would show each of its skills twice.
    expect($loader->skillProviderClasses())->toBe([StubSkillProvider::class, StubSkillProvider::class])
        ->and(makeSkillsContainer($loader)->get(SkillProviderRegistry::class)->sources())
        ->toBe(['filesystem', 'stub']);
});

it('the real kernel serves the shipped skills through the registry', function (): void {
    $c = (new Kernel())->getContainer();

    $names = array_map(
        static fn($s): string => $s->name,
        $c->get(SkillProviderRegistry::class)->getSkills(null),
    );

    expect($names)->toContain('agent-creation');
});

it('the container resolves the filesystem provider over the scanner', function (): void {
    $c = (new Kernel())->getContainer();

    $provider = $c->get(FilesystemSkillProvider::class);

    expect($provider->getSkills(null))->not->toBe([]);
});

it('the container gives the scanner a tool-name resolver', function (): void {
    // Without it the scanner cannot tell a typo'd `allowed-tools` entry from a
    // tool the install simply does not have, and the finding is silent.
    $c = (new Kernel())->getContainer();

    $toolNames = (new ReflectionProperty(SkillScanner::class, 'toolNames'))
        ->getValue($c->get(SkillScanner::class));

    expect($toolNames)->toBeInstanceOf(ToolConfigNameResolver::class);
});

it('the scanner and the filesystem provider agree on what exists', function (): void {
    // The provider memoises; the scanner does not. Comparing them is what
    // catches a memo populated from the wrong roots.
    $c = (new Kernel())->getContainer();

    $scanned = array_map(
        static fn($s): string => $s->name(),
        $c->get(SkillScanner::class)->scan(),
    );
    $served = array_map(
        static fn($s): string => $s->name,
        $c->get(FilesystemSkillProvider::class)->getSkills(null),
    );

    expect(array_values(array_unique($served)))->toEqual(array_values(array_unique($scanned)));
});
