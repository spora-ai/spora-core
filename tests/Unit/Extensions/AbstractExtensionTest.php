<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

it('returns the directory containing the concrete subclass as getPath()', function (): void {
    $extension = new EmptyExtension();
    // EmptyExtension is declared in this test file, so getPath() must point here.
    expect($extension->getPath())->toBe(__DIR__);
});

it('caches getPath() so reflection only runs once', function (): void {
    $extension = new EmptyExtension();
    $first = $extension->getPath();
    $second = $extension->getPath();
    expect($second)->toBe($first);
});

it('defaults tools() to an empty array', function (): void {
    expect((new EmptyExtension())->tools())->toBe([]);
});

it('defaults schemaVersion() to 0', function (): void {
    expect((new EmptyExtension())->schemaVersion())->toBe(0);
});

it('defaults migrationsPath() to null', function (): void {
    expect((new EmptyExtension())->migrationsPath())->toBeNull();
});

it('defaults apps() to an empty array', function (): void {
    expect((new EmptyExtension())->apps())->toBe([]);
});

it('subclasses can override only tools() and inherit everything else', function (): void {
    // Build the list through ToolInterface-resolving FQCNs so PHPStan can prove
    // every element is a class-string<ToolInterface>. Real-world tools are
    // declared as such; using strings that don't resolve would be a type error.
    $fooFqcn = \Tests\Fixtures\TestTool::class;
    $barFqcn = \Tests\Fixtures\StubInputTool::class;

    $extension = new ToolsOnlyExtension([$fooFqcn, $barFqcn]);

    expect($extension->tools())->toBe([$fooFqcn, $barFqcn]);
    expect($extension->schemaVersion())->toBe(0);
    expect($extension->migrationsPath())->toBeNull();
    expect($extension->apps())->toBe([]);
});
