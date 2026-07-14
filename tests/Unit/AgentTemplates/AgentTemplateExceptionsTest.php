<?php

declare(strict_types=1);

use Spora\AgentTemplates\Exceptions\AgentImportFailedException;
use Spora\AgentTemplates\Exceptions\AgentTemplateNotFoundException;

test('AgentTemplateNotFoundException extends RuntimeException', function (): void {
    $e = new AgentTemplateNotFoundException('Template "missing" not found.');
    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toBe('Template "missing" not found.');
});

test('AgentImportFailedException extends RuntimeException', function (): void {
    $e = new AgentImportFailedException('Agent 42 disappeared mid-import.');
    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toBe('Agent 42 disappeared mid-import.');
});

test('AgentTemplateImporter throws AgentTemplateNotFoundException on unknown template id', function (): void {
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolConfig = new Spora\Services\ToolConfigService($security, $logger, [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
    ]);
    $importer = new Spora\AgentTemplates\AgentTemplateImporter(
        $toolConfig,
        new Spora\Plugins\PluginLoader([]),
        new Spora\Core\Paths(BASE_PATH),
    );

    expect(fn() => $importer->applyTemplate(1, 'does-not-exist-anywhere'))
        ->toThrow(AgentTemplateNotFoundException::class);
});
