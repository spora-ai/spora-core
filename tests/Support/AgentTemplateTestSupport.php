<?php

declare(strict_types=1);

/*
 * Lives in tests/Support (loaded once from tests/Pest.php) so individual
 * test files don't have to require one another — that pattern re-registers
 * the imported tests in the same Pest context and leaks shared state
 * (env mutations, Monolog buffers) across the suite.
 */

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;

/**
 * Fixture plugin's tools() returns [Tests\Fixtures\TestTool].
 */
function makeToolsPluginLoader(): PluginLoader
{
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_tools'], null);
    $loader->boot();
    return $loader;
}

/**
 * Wires the importer with the same tool_classes set ContainerDefinitions
 * registers so the importer's plugin-missing detection sees the core tools.
 */
function makeImporter(): AgentTemplateImporter
{
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolClasses = [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\AgentMemoryTool::class,
        Spora\Tools\GlobalMemoryTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\HandoverTool::class,
    ];
    $toolConfig = new ToolConfigService($security, $logger, $toolClasses);
    $plugins = new PluginLoader([]);
    $paths = new Paths(BASE_PATH);

    return new AgentTemplateImporter($toolConfig, $plugins, $paths);
}
