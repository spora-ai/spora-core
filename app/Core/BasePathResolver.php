<?php

declare(strict_types=1);

namespace Spora\Core;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionException;

/**
 * Resolves the consumer's project root via reflection on the Composer autoloader.
 *
 * bin/spora is the canonical entry point but is often required from an operator
 * install's stub (vendor/spora-ai/spora-core/bin/spora). In that case, __DIR__
 * resolves to vendor/spora-ai/spora-core/bin/ — wrong for resolving BASE_PATH.
 * The autoloader, however, is loaded by the stub before requiring us, so its
 * file location tells us where the consumer actually lives.
 */
final class BasePathResolver
{
    /**
     * Returns the consumer's project root (e.g. /home/operator/myapp),
     * or null if the Composer autoloader isn't loaded yet.
     */
    public static function resolve(): ?string
    {
        return self::resolveFromClass(ClassLoader::class);
    }

    /**
     * Returns the directory 3 levels above the given class's source file,
     * or null if reflection on the class fails. Exposed so the catch branch
     * can be exercised by tests with a non-existent class name.
     */
    public static function resolveFromClass(string $class): ?string
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (ReflectionException) {
            return null;
        }

        // vendor/composer/ClassLoader.php → up 3 levels → consumer root.

        return dirname((string) $file, 3);
    }
}
