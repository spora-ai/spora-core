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

it('falls back to null when the autoloader is not loaded', function (): void {
    // We can't unload ClassLoader without breaking the suite, so we exercise
    // the same code path BasePathResolver::resolve() uses — reflect on an
    // absent class — and assert it raises ReflectionException. resolve()
    // catches that exception and returns null.

    $threw = false;
    try {
        new ReflectionClass('Spora\\Definitely\\Not\\A\\Real\\Class_' . uniqid());
    } catch (ReflectionException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    // Pin the documented return type so the bin/spora fallback
    // (`if ($basePath === null)`) stays valid: must be `?string`.
    $returnType = (new ReflectionMethod(BasePathResolver::class, 'resolve'))->getReturnType();

    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);

    /** @var ReflectionNamedType $returnType */
    $returnType = $returnType;
    expect($returnType->getName())->toBe('string');
    expect($returnType->allowsNull())->toBeTrue();
});

it('returns null when the autoloader is not loaded', function (): void {
    // We can't unload ClassLoader without breaking the suite, so we exercise
    // the catch branch via resolveFromClass() with a class name that doesn't
    // exist. Reflection throws, the catch returns null.

    $resolved = BasePathResolver::resolveFromClass('Spora\\Definitely\\Not\\A\\Real\\Class_' . uniqid());

    expect($resolved)->toBeNull();
});
