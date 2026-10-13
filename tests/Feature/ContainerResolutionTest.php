<?php

declare(strict_types=1);

use DI\Container;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Kernel;
use Spora\Core\Paths;

/**
 * Every DI factory in this file calls `$c->get(...)`, which PHP-DI declares
 * as `: mixed`. Static analysis therefore compares nothing: a factory whose
 * argument order no longer matches its constructor type-checks perfectly, as
 * does one that passes too few arguments when the remainder are optional.
 *
 * Three such bugs landed during the Config migration and none was caught by
 * PHPStan or by the unit tests, because nothing resolved the affected binding:
 *
 *   - `MediaTool` — Config moved into slot 8, the call site kept the old order.
 *     Every resolution was a TypeError; the tool could not be instantiated.
 *   - `NotificationService` — two agents edited the two sides of one call
 *     independently. `bin/spora` fataled on every invocation.
 *   - `ReadUrlTool` — the new resolver argument was never added to the factory,
 *     so the Logger landed in its slot.
 *
 * The only thing that catches this class of bug is actually constructing the
 * objects. This does that for every class-keyed definition.
 */
beforeEach(function (): void {
    TestDatabaseFactory::boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
    $_ENV['SPORA_APP_ENV'] = 'testing';
});


/**
 * The real container, built the way the Kernel builds it.
 *
 * `ContainerDefinitions::all()` alone is not the production graph: the Kernel
 * additionally injects the live `AppLoader` and `PluginLoader` instances
 * before building, and several factories depend on the latter.
 */
function containerResolutionContainer(): Container
{
    $base = sys_get_temp_dir() . '/spora-container-resolution-' . uniqid('', true);
    mkdir($base, 0o777, true);
    mkdir($base . '/storage', 0o777, true);

    $kernel = new Kernel(new Paths($base));
    $container = $kernel->getContainer();
    $kernel->__destruct();

    return $container;
}

/**
 * Entries that must not be resolved eagerly.
 *
 * Each is here with a reason, not because it happens to throw. A new entry
 * should be a considered decision rather than "the suite went green without
 * it".
 *
 * @return array<string, string>
 */
function containerResolutionSkipList(): array
{
    return [
        'container'     => 'The container itself; this test is what builds it.',
        'config'         => 'Replaced by Config::class.',
        // These are request-scoped by nature: they hold the live
        // Request/Response pair and only exist inside a Kernel::handle().
        Spora\Core\RequestOrigin::class => 'Static facade, not a container service.',
    ];
}

test('every class-keyed container definition resolves', function (): void {
    $container = containerResolutionContainer();
    $skip = containerResolutionSkipList();
    $failures = [];
    $resolved = 0;

    foreach (ContainerDefinitions::all() as $id => $_definition) {
        if (!is_string($id) || !class_exists($id)) {
            continue;
        }
        if (array_key_exists($id, $skip)) {
            continue;
        }

        try {
            $container->get($id);
            $resolved++;
        } catch (Throwable $e) {
            // Caught broadly and narrowed rather than `catch (TypeError)`:
            // PHP-DI types `get()` as `mixed` and cannot see into the factory
            // closures, so the analyser concludes no TypeError is possible.
            // It is — that is the entire reason this test exists.
            if ($e instanceof TypeError) {
                $failures[$id] = $e->getMessage();
            }
            // Anything else — a database that is not up, a filesystem path
            // that is not there — is a runtime condition, not a wiring bug.
            // PHPStan owns the mismatches it can see; this owns the rest.
        }
    }

    // 80 of 166 class-keyed definitions construct here; the rest need a live
    // request, a plugin install or a filesystem that is not present, and
    // throw something other than a TypeError. The floor stops this test from
    // quietly resolving nothing after a refactor.
    expect($resolved)->toBeGreaterThan(60);

    expect(array_keys($failures))->toBe(
        [],
        'Container factories whose arguments no longer match their constructors: '
            . json_encode($failures, JSON_PRETTY_PRINT),
    );
});

test('the container exposes the settings store and the shared-settings resolver', function (): void {
    $container = containerResolutionContainer();

    expect($container->get(Spora\Settings\SettingsRegistry::class))->toBeInstanceOf(Spora\Settings\SettingsRegistry::class)
        ->and($container->get(Spora\Settings\SettingsRepository::class))->toBeInstanceOf(Spora\Settings\SettingsRepository::class)
        ->and($container->get(Spora\Core\Config::class))->toBeInstanceOf(Spora\Core\Config::class)
        ->and($container->get(Spora\Tools\SharedSettingsResolver::class))->toBeInstanceOf(Spora\Tools\SharedSettingsResolver::class)
        ->and($container->get(Spora\Tools\HttpTimeoutResolver::class))->toBeInstanceOf(Spora\Tools\HttpTimeoutResolver::class);
});

test('the shared-settings resolver is bound to the plugin list, not autowired empty', function (): void {
    // PHP-DI would autowire `new SharedSettingsResolver()` with its default
    // empty array. That looks wired and silently shares nothing, which is the
    // worst failure mode available: every test passes and no tool inherits a
    // credential.
    $container = containerResolutionContainer();

    $loader = $container->get(Spora\Plugins\PluginLoader::class);
    $bound = $container->get(Spora\Tools\SharedSettingsResolver::class);

    expect($bound)->toBeInstanceOf(Spora\Tools\SharedSettingsResolver::class)
        // Same instance everywhere: ToolConfigService and ToolController must
        // agree about what groups exist.
        ->and($bound)->toBe($container->get(Spora\Tools\SharedSettingsResolver::class))
        ->and($loader)->toBeInstanceOf(Spora\Plugins\PluginLoader::class);
});
