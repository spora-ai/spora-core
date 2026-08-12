<?php

declare(strict_types=1);

use Spora\Console\Commands\PluginListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;
use Tests\Support\PluginFixtures;
use Tests\Support\PluginManagerFactory;

function makePluginListTester(string $basePath): CommandTester
{
    $manager = PluginManagerFactory::build(new FakeProcessFactory(), basePath: $basePath);

    $command = new PluginListCommand($manager);
    $command->setName('plugin:list');

    return new CommandTester($command);
}

it('prints an empty-state message when no plugins are installed', function (): void {
    PluginFixtures::withTree([], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('No Spora plugins installed');
    });
});

it('renders a table with one row per installed plugin', function (): void {
    // Fixture packages aren't installed via Composer — version is null for
    // every row, surfaced as `(unknown)`. The InstalledVersions path itself
    // is covered by the dedicated regression in PluginManagerTest.
    PluginFixtures::withTree([
        'tavily'    => ['name' => 'spora-ai/spora-plugin-tavily'],
        'semantics' => ['name' => 'spora-ai/spora-plugin-semantic-scholar'],
        'minimax'   => ['name' => 'spora-ai/spora-plugin-minimax'],
    ], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        $display = $tester->getDisplay();
        expect($display)
            ->toContain('spora-ai/spora-plugin-tavily')
            ->toContain('spora-ai/spora-plugin-semantic-scholar')
            ->toContain('spora-ai/spora-plugin-minimax');
        // Three plugins × one (unknown) cell each.
        expect(substr_count($display, '(unknown)'))->toBe(3);
    });
});

it('survives macOS /tmp symlink resolution (path column matches what list() returns)', function (): void {
    PluginFixtures::withTree([
        'tavily' => ['name' => 'spora-ai/spora-plugin-tavily'],
    ], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);
        $display = $tester->getDisplay();

        expect($display)->toContain($base . '/plugins/tavily');
        expect($display)->not->toContain(sys_get_temp_dir() . '/spora-plugins-');
    }, tag: 'spora-list-macos-symlink');
});
