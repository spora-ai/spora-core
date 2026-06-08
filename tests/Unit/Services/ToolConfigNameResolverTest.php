<?php

declare(strict_types=1);

use Monolog\Logger;
use Spora\Services\ToolConfigNameResolver;
use Tests\Fixtures\TestTool;

function makeNameResolver(): ToolConfigNameResolver
{
    return new ToolConfigNameResolver(new Logger('test'), [TestTool::class]);
}

test('getToolName reads the value of the #[Tool(name: ...)] attribute', function (): void {
    $resolver = makeNameResolver();

    // TestTool declares #[Tool(name: 'test_tool')].
    expect($resolver->getToolName(TestTool::class))->toBe('test_tool');
});

test('resolveToolClass maps a tool name to its fully-qualified class', function (): void {
    $resolver = makeNameResolver();

    expect($resolver->resolveToolClass('test_tool'))->toBe(TestTool::class);
});

test('resolveToolClass returns null for an unknown tool name', function (): void {
    $resolver = makeNameResolver();

    expect($resolver->resolveToolClass('not_a_real_tool'))->toBeNull();
});

test('getRegisteredToolClasses returns the constructor list as-is', function (): void {
    $resolver = makeNameResolver();

    expect($resolver->getRegisteredToolClasses())->toBe([TestTool::class]);
});
