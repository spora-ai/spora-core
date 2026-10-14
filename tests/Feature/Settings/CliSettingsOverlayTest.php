<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Config;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

/**
 * `applySettingsOverlay()` had exactly two callers: `Kernel::handle()` and
 * `MailConfigController`. `bin/spora` builds a kernel and pulls commands out
 * of the container without ever calling `handle()`, so every CLI process read
 * defaults, `config.php` and `.env` only.
 *
 * The admin page showed `source: database` for values the worker would never
 * read. Two fixes landed, and they are independent: the commands opt into the
 * overlay, and the settings a worker cannot honour after it has started
 * (`SPORA_WORKER_RUNTIME_MODE`, `SPORA_MAX_WORKERS`) are `bootOnly`, so no
 * value can reach the page in the first place.
 */
beforeEach(function (): void {
    TestDatabaseFactory::boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
});

function cliContainer(): Config
{
    $registry = new SettingsRegistry(new Spora\Core\Paths(BASE_PATH));
    $builder  = new ContainerBuilder();
    $builder->addDefinitions(Spora\Core\ContainerDefinitions::all());
    $container = $builder->build();

    $config = $container->get(Config::class);
    $config->applySettingsOverlay(
        new SettingsRepository(new SecurityManager(random_bytes(32)), $registry),
        $registry,
    );

    return $config;
}

it('reads a stored setting on the CLI path', function (): void {
    $registry = new SettingsRegistry(new Spora\Core\Paths(BASE_PATH));
    (new SettingsRepository(new SecurityManager(random_bytes(32)), $registry))
        ->put($registry->get('SPORA_TOOL_HTTP_TIMEOUT'), 7, null);

    $config = cliContainer();

    expect($config->sourceOf('SPORA_TOOL_HTTP_TIMEOUT'))->toBe('database')
        ->and($config->getInt('SPORA_TOOL_HTTP_TIMEOUT'))->toBe(7);
});

it('does not read a stored setting without the overlay — the bug', function (): void {
    $registry = new SettingsRegistry(new Spora\Core\Paths(BASE_PATH));
    (new SettingsRepository(new SecurityManager(random_bytes(32)), $registry))
        ->put($registry->get('SPORA_TOOL_HTTP_TIMEOUT'), 7, null);

    // Exactly what `bin/spora` produced: the container's Config, untouched.
    $builder = new ContainerBuilder();
    $builder->addDefinitions(Spora\Core\ContainerDefinitions::all());
    $config = $builder->build()->get(Config::class);

    // The same row, the same key, the same value as the test above — only the
    // overlay is missing, so the default is what survives.
    expect($config->sourceOf('SPORA_TOOL_HTTP_TIMEOUT'))->toBe('default')
        ->and($config->getInt('SPORA_TOOL_HTTP_TIMEOUT'))
        ->toBe($registry->get('SPORA_TOOL_HTTP_TIMEOUT')->default);
});

it('the worker command opts into the overlay', function (): void {
    $source = (string) file_get_contents(BASE_PATH . '/app/Console/Commands/WorkerRunCommand.php');

    expect($source)->toContain('use AppliesSettingsOverlay;')
        ->and($source)->toContain('$this->applyRuntimeSettings($this->container, $this->database)');
});

it('every command that resolves settings-backed services opts in', function (mixed $command): void {
    $source = (string) file_get_contents(BASE_PATH . '/app/Console/Commands/' . $command . '.php');

    expect($source)->toContain('use AppliesSettingsOverlay;');
})->with([
    'WorkerRunCommand' => 'WorkerRunCommand',
    'TaskRunCommand'   => 'TaskRunCommand',
    'SeedCommand'      => 'SeedCommand',
    'InstallCommand'   => 'InstallCommand',
]);

it('db:reset stays exempt, so resetting a database never needs to read it', function (): void {
    // The reason the overlay is opt-in rather than applied once in bin/spora.
    $source = (string) file_get_contents(BASE_PATH . '/app/Console/Commands/DbResetCommand.php');

    expect($source)->not->toContain('use AppliesSettingsOverlay;');
});

it('reads the Mercure legacy-user-topic flag through Config, not the raw environment', function (): void {
    // It used to be read from $_ENV while being a declared, DB-storable
    // setting, so the admin page listed it as editable and a stored value was
    // silently ignored. The docblock said it was not declared; both of its
    // claims were false.
    $registry = new SettingsRegistry(new Spora\Core\Paths(BASE_PATH));
    (new SettingsRepository(new SecurityManager(random_bytes(32)), $registry))
        ->put($registry->get('SPORA_MERCURE_LEGACY_USER_TOPIC'), true, null);

    expect(cliContainer()->getBool('SPORA_MERCURE_LEGACY_USER_TOPIC'))->toBeTrue();
});

it('no longer carries a raw-env reader for the flag', function (): void {
    $source = (string) file_get_contents(BASE_PATH . '/app/Core/OrchestratorContainerBindings.php');

    expect($source)->not->toContain('mercureLegacyUserTopicEnabled')
        ->and($source)->not->toContain("\$_ENV['SPORA_MERCURE_LEGACY_USER_TOPIC']");
});
