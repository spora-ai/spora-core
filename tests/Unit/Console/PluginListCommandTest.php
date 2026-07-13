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
    // No FakeProcessFactory needed — PluginManager::list() no longer shells
    // out. We pass an empty factory for symmetry with the other command
    // tests; list() never invokes it.
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
    PluginFixtures::withTree([
        'tavily'    => ['name' => 'spora-ai/spora-plugin-tavily',           'version' => '0.1.0'],
        'semantics' => ['name' => 'spora-ai/spora-plugin-semantic-scholar', 'version' => '0.2.0'],
        'minimax'   => ['name' => 'spora-ai/spora-plugin-minimax',          'version' => '0.5.0'],
    ], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())
            ->toContain('spora-ai/spora-plugin-tavily')
            ->toContain('0.1.0')
            ->toContain('spora-ai/spora-plugin-semantic-scholar')
            ->toContain('0.2.0')
            ->toContain('spora-ai/spora-plugin-minimax')
            ->toContain('0.5.0');
    });
});

it('renders (unknown) for plugins whose composer.json has no version', function (): void {
    PluginFixtures::withTree([
        'tavily' => ['name' => 'spora-ai/spora-plugin-tavily'], // no version key
    ], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('(unknown)');
    });
});

it('survives macOS /tmp symlink resolution (path column matches what list() returns)', function (): void {
    // Regression guard: PluginManager::list() applies realpath() to each
    // manifest, so the `path` column in the rendered table is the resolved
    // path. On macOS /tmp → /private/tmp; the test base must be the same
    // canonical form so callers can compare plugin paths verbatim. The
    // helper now returns the resolved base — verify a plugin in it
    // appears under the resolved directory, and that the unresolved prefix
    // does not.
    PluginFixtures::withTree([
        'tavily' => ['name' => 'spora-ai/spora-plugin-tavily', 'version' => '0.1.0'],
    ], function (string $base): void {
        $tester = makePluginListTester($base);
        $tester->execute([]);
        $display = $tester->getDisplay();

        expect($display)->toContain($base . '/plugins/tavily');
        expect($display)->not->toContain(sys_get_temp_dir() . '/spora-plugins-');
    }, tag: 'spora-list-macos-symlink');
});
