<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Tools\HttpTimeoutResolver;

function resolverWithGlobal(mixed $globalTimeout): HttpTimeoutResolver
{
    return new HttpTimeoutResolver(
        $globalTimeout === null ? new Config() : new Config(['SPORA_TOOL_HTTP_TIMEOUT' => $globalTimeout]),
    );
}

it('prefers the tool setting over SPORA_TOOL_HTTP_TIMEOUT and the fallback', function () {
    expect(resolverWithGlobal(90)->resolve(12))->toBe(12);
});

it('falls back to SPORA_TOOL_HTTP_TIMEOUT when the tool setting is absent', function () {
    expect(resolverWithGlobal(90)->resolve(null))->toBe(90);
});

it('falls back to the default when neither the tool setting nor the global is set', function () {
    expect(resolverWithGlobal(null)->resolve(null))->toBe(30);
});

it('honours an explicit fallback when the caller supplies one', function () {
    expect(resolverWithGlobal(null)->resolve(null, 5))->toBe(5);
});

it('takes the global from the injected Config, never from the environment', function () {
    // The rule this replaced read `$_ENV`/`getenv()` directly, which is why an
    // operator who set this in Global Settings saw it saved and nothing change.
    // A stale environment variable must not decide the outcome either way.
    putenv('SPORA_TOOL_HTTP_TIMEOUT=17');
    $_ENV['SPORA_TOOL_HTTP_TIMEOUT'] = '17';

    try {
        expect(resolverWithGlobal(null)->resolve(null))->toBe(30);
        expect(resolverWithGlobal(90)->resolve(null))->toBe(90);
    } finally {
        putenv('SPORA_TOOL_HTTP_TIMEOUT');
        unset($_ENV['SPORA_TOOL_HTTP_TIMEOUT']);
    }
});

it('treats zero and negative values as unset at every layer', function (mixed $toolSetting, mixed $global, int $expected) {
    expect(resolverWithGlobal($global)->resolve($toolSetting, 30))->toBe($expected);
})->with([
    'tool setting 0 falls through to the global' => [0, 90, 90],
    'tool setting 0 with no global'             => [0, null, 30],
    'negative tool setting falls through'        => [-5, 90, 90],
    'negative tool setting with no global'       => [-5, null, 30],
    'zero global falls through to the fallback'  => [null, 0, 30],
    'negative global falls through'              => [null, -5, 30],
    'zero in both layers'                        => [0, 0, 30],
]);

it('never returns zero or a negative, whatever it is handed', function (mixed $toolSetting) {
    $resolved = resolverWithGlobal(45)->resolve($toolSetting, 30);

    expect($resolved)->toBeGreaterThan(0);
})->with([
    'empty string'    => [''],
    'non-numeric'     => ['thirty'],
    'null byte'       => ["\0"],
    'array'           => [['30']],
    'boolean true'    => [true],
    'boolean false'   => [false],
    'float zero'      => [0.0],
    'negative string' => ['-1'],
]);

it('does not throw on a non-numeric tool setting', function () {
    resolverWithGlobal(45)->resolve('not a number');
})->throwsNoExceptions();

it('reads a numeric string tool setting as seconds', function () {
    expect(resolverWithGlobal(90)->resolve('12'))->toBe(12);
});