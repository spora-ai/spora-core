<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginUpdateCommand;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makePluginUpdateTester(FakeProcessFactory $factory): CommandTester
{
    $manager = new PluginManager(
        new NullLogger(),
        \Closure::fromCallable($factory),
        '/srv/spora',
    );

    $command = new PluginUpdateCommand($manager);
    $command->setName('spora:plugin:update');

    return new CommandTester($command);
}

it('updates a single plugin when a package is supplied', function (): void {
    $factory = new FakeProcessFactory([
        'composer update spora-ai/spora-plugin-tavily --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', "Updating spora-ai/spora-plugin-tavily\n"),
    ]);

    $tester = makePluginUpdateTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('Updated spora-ai/spora-plugin-tavily');
    expect($factory->calls[0]['argv'])->toBe([
        'composer', 'update', 'spora-ai/spora-plugin-tavily',
        '--no-interaction', '--no-progress', '--optimize-autoloader',
    ]);
});

it('updates every installed plugin when no package is supplied', function (): void {
    $factory = new FakeProcessFactory([
        'composer update --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', "Updating all plugins\n"),
    ]);

    $tester = makePluginUpdateTester($factory);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('Updated all plugins');
    expect($factory->calls[0]['argv'])->toBe([
        'composer', 'update',
        '--no-interaction', '--no-progress', '--optimize-autoloader',
    ]);
});

it('translates PluginInstallFailedException into a failure exit code', function (): void {
    $factory = new FakeProcessFactory([
        'composer update spora-ai/spora-plugin-tavily --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', '', 'network unreachable', 1),
    ]);

    $tester = makePluginUpdateTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily']);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('composer exited 1');
});
