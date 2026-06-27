<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginListCommand;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makePluginListTester(FakeProcessFactory $factory): CommandTester
{
    $manager = new PluginManager(
        new NullLogger(),
        Closure::fromCallable($factory),
        '/srv/spora',
    );

    $command = new PluginListCommand($manager);
    $command->setName('plugin:list');

    return new CommandTester($command);
}

it('prints an empty-state message when no plugins are installed', function (): void {
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', '[]'),
    ]);

    $tester = makePluginListTester($factory);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('No Spora plugins installed');
});

it('renders a table with one row per installed plugin', function (): void {
    // Real Composer wraps the package list under an `installed` key since v2.
    $json = json_encode(['installed' => [
        ['name' => 'spora-ai/spora-plugin-tavily',     'version' => '0.1.0', 'type' => 'spora-plugin', 'path' => '/srv/spora/plugins/tavily'],
        ['name' => 'spora-ai/spora-plugin-semantics',  'version' => '0.2.0', 'type' => 'spora-plugin', 'path' => '/srv/spora/plugins/semantics'],
        ['name' => 'symfony/console',                  'version' => '8.0.0', 'type' => 'library'],
    ]]);
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', $json),
    ]);

    $tester = makePluginListTester($factory);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('spora-ai/spora-plugin-tavily')
        ->toContain('0.1.0')
        ->toContain('spora-ai/spora-plugin-semantics')
        ->toContain('0.2.0');
});

it('renders (unknown) for plugins whose version is not reported', function (): void {
    $json = json_encode(['installed' => [
        ['name' => 'spora-ai/spora-plugin-x', 'version' => null, 'type' => 'spora-plugin', 'path' => '/srv/spora/plugins/x'],
    ]]);
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', $json),
    ]);

    $tester = makePluginListTester($factory);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('(unknown)');
});
