<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginInstallCommand;
use Spora\Core\Extension\PluginManager;
use Spora\Core\Paths;
use Spora\Core\Version;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makePluginInstallTester(FakeProcessFactory $factory): CommandTester
{
    $manager = new PluginManager(
        new NullLogger(),
        Closure::fromCallable($factory),
        new Paths('/srv/spora'),
    );

    $command = new PluginInstallCommand($manager);
    $command->setName('plugin:install');

    return new CommandTester($command);
}

function makePluginInstallApplicationTester(FakeProcessFactory $factory): ApplicationTester
{
    $manager = new PluginManager(
        new NullLogger(),
        Closure::fromCallable($factory),
        new Paths('/srv/spora'),
    );

    $command = new PluginInstallCommand($manager);
    $command->setName('plugin:install');

    // Mirrors bin/spora: the version argument makes Application auto-register
    // a global --version option, which is what triggered the original
    // InputDefinition collision against the command's local `version` option.
    $application = new Application('Spora CLI', Version::current());
    $application->setAutoExit(false);
    $application->addCommand($command);

    return new ApplicationTester($application);
}

it('installs a registry package and reports success', function (): void {
    $factory = new FakeProcessFactory([
        'composer require spora-ai/spora-plugin-tavily --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', "Installing spora-ai/spora-plugin-tavily\n"),
    ]);

    $tester = makePluginInstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('Installed spora-ai/spora-plugin-tavily');
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-tavily');
});

it('appends the version constraint when --constraint is supplied', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily', '--constraint' => '^1.0']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-tavily:^1.0');
});

it('passes --path through to a path-repo install', function (): void {
    $tmpPath = sys_get_temp_dir() . '/spora-fake-plugin-' . uniqid();
    mkdir($tmpPath);

    try {
        $factory = new FakeProcessFactory();

        $tester = makePluginInstallTester($factory);
        $tester->execute(['package' => 'spora-ai/spora-plugin-x', '--path' => $tmpPath]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($factory->calls)->toHaveCount(2);
        expect($factory->calls[0]['argv'][0])->toBe('composer');
        expect($factory->calls[0]['argv'][1])->toBe('config');
        expect($factory->calls[1]['argv'][2])->toBe('spora-ai/spora-plugin-x:*@dev');
    } finally {
        rmdir($tmpPath);
    }
});

it('rejects passing both --constraint and --path without invoking the manager', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallTester($factory);
    $tester->execute([
        'package'      => 'spora-ai/spora-plugin-x',
        '--constraint' => '^1.0',
        '--path'       => '/srv/plugin-x',
    ]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('either --constraint or --path, not both');
    expect($factory->calls)->toBe([]);
});

it('translates PluginInstallFailedException into a failure exit code', function (): void {
    $factory = new FakeProcessFactory([
        'composer require spora-ai/spora-plugin-x --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', '', 'Could not find package', 2),
    ]);

    $tester = makePluginInstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-x']);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('composer exited 2');
});

it('does not collide with the global --version option (ApplicationTester)', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallApplicationTester($factory);

    // Pre-rename regression: Command::mergeApplicationDefinition() folded the
    // command's `version` option into a full definition that already had the
    // global --version, throwing
    // "An option named 'version' already exists." from Application::doRun().
    // CommandTester alone wouldn't catch this because it skips the merge —
    // only ApplicationTester (which calls Application::run → doRun →
    // mergeApplicationDefinition) reproduces the production code path.
    // Post-rename the command has no `version` option, so the merge succeeds
    // and --version reaches the global handler, which prints the app version
    // and exits 0 without touching the command.
    $exit = $tester->run(['command' => 'plugin:install', 'package' => 'spora-ai/spora-plugin-tavily', '--version' => true]);

    expect($exit)->toBe(0);
    expect($tester->getDisplay())->toContain('Spora CLI');
    expect($factory->calls)->toBe([]);
});

it('runs plugin:install via ApplicationTester when --constraint is supplied', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallApplicationTester($factory);
    $exit = $tester->run(['command' => 'plugin:install', 'package' => 'spora-ai/spora-plugin-tavily', '--constraint' => '^1.0']);

    expect($exit)->toBe(Command::SUCCESS);
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-tavily:^1.0');
});
