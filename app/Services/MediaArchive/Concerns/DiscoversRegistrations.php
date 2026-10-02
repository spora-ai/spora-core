<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Concerns;

use InvalidArgumentException;

/**
 * Scaffolding for the media-archive static registries: a process-global
 * ordered list of FQCNs that a service reads at construction time.
 *
 * A trait, not an abstract base class, because a `private static`
 * property declared on a parent is *shared* by every subclass that does
 * not redeclare it — an abstract `ClassListDiscovery` base would silently
 * merge the converter, producer and refiner registries into one shared
 * list. Trait composition copies the property into each final class, so
 * every registry keeps its own storage and the three remain independent.
 *
 * PHP-DI v7 is the reason the list is static at all: it ships no runtime
 * queryable tag store, so core populates each list in
 * {@see \Spora\Core\ContainerDefinitions} and plugins append to it from
 * their `register(ContainerBuilder)` hook.
 *
 * @template T of object
 */
trait DiscoversRegistrations
{
    /** @var list<class-string<T>> */
    private static array $registered = [];

    /**
     * The interface every registered FQCN must implement. Doubles as the
     * trait's type parameter, which is how each concrete registry keeps
     * its `class-string<ThatInterface>` narrowing on the public methods
     * after the shared body is extracted.
     *
     * @return class-string<T>
     */
    abstract protected static function registrationContract(): string;

    /**
     * Add a registration. Idempotent: adding the same FQCN twice is a
     * no-op (no duplicates).
     *
     * @param class-string<T> $class
     */
    public static function add(string $class): void
    {
        $contract = static::registrationContract();
        if (!is_subclass_of($class, $contract)) {
            throw new InvalidArgumentException(sprintf(
                '%s::add: %s does not implement %s',
                static::class,
                $class,
                $contract,
            ));
        }
        if (!in_array($class, self::$registered, true)) {
            self::$registered[] = $class;
        }
    }

    /**
     * @return list<class-string<T>>
     */
    public static function all(): array
    {
        return self::$registered;
    }

    /**
     * Test-only: clear the registry between test runs.
     */
    public static function reset(): void
    {
        self::$registered = [];
    }
}
