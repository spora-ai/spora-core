<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginInstallCommand;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makePluginInstallTester(FakeProcessFactory $factory): CommandTester
{
    $manager = new PluginManager(
        new NullLogger(),
        \Closure::fromCallable($factory),
        '/srv/spora',
    );

    $command = new PluginInstallCommand($manager);
    $command->setName('spora:plugin:install');

    return new CommandTester($command);
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

it('appends the version constraint when --version is supplied', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily', '--version' => '^1.0']);

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

it('rejects passing both --version and --path without invoking the manager', function (): void {
    $factory = new FakeProcessFactory();

    $tester = makePluginInstallTester($factory);
    $tester->execute([
        'package'    => 'spora-ai/spora-plugin-x',
        '--version'  => '^1.0',
        '--path'     => '/srv/plugin-x',
    ]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('either --version or --path, not both');
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
