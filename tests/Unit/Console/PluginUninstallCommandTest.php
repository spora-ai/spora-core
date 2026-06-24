<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginUninstallCommand;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makePluginUninstallTester(FakeProcessFactory $factory): CommandTester
{
    $manager = new PluginManager(
        new NullLogger(),
        Closure::fromCallable($factory),
        '/srv/spora',
    );

    $command = new PluginUninstallCommand($manager);
    $command->setName('spora:plugin:uninstall');

    return new CommandTester($command);
}

it('uninstalls the package and reports success', function (): void {
    $factory = new FakeProcessFactory([
        'composer remove spora-ai/spora-plugin-tavily --no-interaction --no-progress' =>
            new InMemoryProcess([], '', "Removing spora-ai/spora-plugin-tavily\n"),
    ]);

    $tester = makePluginUninstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('Uninstalled spora-ai/spora-plugin-tavily');
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-tavily');
});

it('translates PluginInstallFailedException into a failure exit code', function (): void {
    $factory = new FakeProcessFactory([
        'composer remove spora-ai/spora-plugin-tavily --no-interaction --no-progress' =>
            new InMemoryProcess([], '', '', 'package not installed', 1),
    ]);

    $tester = makePluginUninstallTester($factory);
    $tester->execute(['package' => 'spora-ai/spora-plugin-tavily']);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('composer exited 1');
});
