<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Spora\Core\BasePathResolver;

it('resolves BASE_PATH from the autoloader location when loaded', function (): void {
    // The Pest bootstrap loads vendor/autoload.php, so ClassLoader is in memory
    // and BasePathResolver::resolve() should return the consumer root
    // (the directory containing vendor/).
    $resolved = BasePathResolver::resolve();

    expect($resolved)->not->toBeNull();
    // vendor/composer/ClassLoader.php → dirname(..., 3) → repo root.
    expect($resolved)->toBe(dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 3));
    // The resolved path must point at a real directory containing vendor/.
    expect(is_dir($resolved))->toBeTrue();
    expect(is_dir($resolved . '/vendor'))->toBeTrue();
});

it('returns null when the autoloader is not loaded', function (): void {
    // We can't unload ClassLoader without breaking the suite, so we exercise
    // the catch branch via resolveFromClass() with a class name that doesn't
    // exist. Reflection throws, the catch returns null.

    $resolved = BasePathResolver::resolveFromClass('Spora\\Definitely\\Not\\A\\Real\\Class_' . uniqid());

    expect($resolved)->toBeNull();
});
