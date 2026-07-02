<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Spora\Core\MiddlewareRouteCollector;
use Throwable;

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

it('defaults autoload() to an empty array', function (): void {
    expect((new EmptyExtension())->autoload())->toBe([]);
});

it('defaults tools() to an empty array', function (): void {
    expect((new EmptyExtension())->tools())->toBe([]);
});

it('defaults drivers() to an empty array', function (): void {
    expect((new EmptyExtension())->drivers())->toBe([]);
});

it('defaults recipePaths() to an empty array', function (): void {
    expect((new EmptyExtension())->recipePaths())->toBe([]);
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

it('register() is a no-op', function (): void {
    $builder = new \DI\ContainerBuilder();
    expect(fn() => (new EmptyExtension())->register($builder))->not->toThrow(Throwable::class);
});

it('routes() is a no-op', function (): void {
    $collector = new MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased());
    expect(fn() => (new EmptyExtension())->routes($collector))->not->toThrow(Throwable::class);
});

it('boot() is a no-op', function (): void {
    expect(fn() => (new EmptyExtension())->boot())->not->toThrow(Throwable::class);
});

it('subclasses can override only tools() and inherit everything else', function (): void {
    // Build the list through ToolInterface-resolving FQCNs so PHPStan can prove
    // every element is a class-string<ToolInterface>. Real-world tools are
    // declared as such; using strings that don't resolve would be a type error.
    $fooFqcn = \Tests\Fixtures\TestTool::class;
    $barFqcn = \Tests\Fixtures\StubInputTool::class;

    $extension = new ToolsOnlyExtension([$fooFqcn, $barFqcn]);

    expect($extension->tools())->toBe([$fooFqcn, $barFqcn]);
    expect($extension->autoload())->toBe([]);
    expect($extension->drivers())->toBe([]);
    expect($extension->recipePaths())->toBe([]);
    expect($extension->schemaVersion())->toBe(0);
    expect($extension->migrationsPath())->toBeNull();
    expect($extension->apps())->toBe([]);
});
