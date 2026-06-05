<?php

declare(strict_types=1);

use LogicException;
use Spora\Models\ToolConfiguration;

it('uses the tool_configurations table', function (): void {
    $config = new ToolConfiguration();

    expect($config->getTable())->toBe('tool_configurations');
});

it('allows mass assignment of tool_class, tool_name, settings', function (): void {
    $config = ToolConfiguration::create([
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'tool_name'  => 'stub_output',
        'settings'   => 'encrypted-blob',
    ]);

    expect($config->getAttribute('tool_class'))->toBe('Spora\Tools\StubOutputTool')
        ->and($config->getAttribute('tool_name'))->toBe('stub_output')
        ->and($config->getAttributes())->toHaveKey('settings');
});

it('throws LogicException when settings attribute is accessed directly', function (): void {
    $config = new ToolConfiguration();

    expect(fn() => $config->settings)->toThrow(LogicException::class);
});
