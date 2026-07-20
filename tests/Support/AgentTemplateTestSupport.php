<?php

declare(strict_types=1);

/*
 * Shared helpers for AgentTemplateImporter / AgentTemplateExporter tests.
 *
 * Previously these functions lived as global functions at the top of the
 * individual test files, which forced AgentTemplateExporterTest.php to
 * `require_once` its sibling test files just to pick them up. That
 * side-effect re-registered every test in the imported files in the same
 * Pest execution context, polluting shared state (e.g. env mutations in
 * ContainerDefinitionsTest) and triggering Pest 4's risky-test detection
 * on unrelated tests in the suite.
 *
 * Centralising the helpers here lets each test file stay independent:
 * Pest.php requires this support file once, the helpers become available
 * globally, and no test file needs to require another test file.
 */

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;

/**
 * Build a real PluginLoader that loads the tools-contributing fixture plugin.
 * The fixture's tools() returns [Tests\Fixtures\TestTool].
 */
function makeToolsPluginLoader(): PluginLoader
{
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_tools'], null);
    $loader->boot();
    return $loader;
}

/**
 * Build an AgentTemplateImporter wired with the core tool classes (the same
 * set ContainerDefinitions registers) and an empty PluginLoader. Tests
 * exercise the tool-class lookup path that does not depend on plugins.
 */
function makeImporter(): AgentTemplateImporter
{
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    // Mirror the 'tool_classes' config from ContainerDefinitions so the
    // importer's plugin-missing detection sees the core tools.
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
    // PluginLoader without directories boots an empty loader; tests
    // exercise the tool-class lookup path that doesn't depend on plugins.
    $plugins = new PluginLoader([]);
    $paths = new Paths(BASE_PATH);

    return new AgentTemplateImporter($toolConfig, $plugins, $paths);
}
