<?php

declare(strict_types=1);

namespace Spora\Drivers;

use InvalidArgumentException;

/**
 * The reasoning-effort levels one LLM configuration offers.
 *
 * The set belongs to the configuration, not to the driver class: an endpoint
 * reached through a custom `base_url` speaks a vocabulary of its own, and
 * the names in it are the operator's to choose.
 *
 * The bounds are a security and a storage decision, not a preference. A name
 * travels into a third-party request body — so it may not carry anything
 * that could corrupt that body — and into a `string(16)` column, whose
 * widening is a full table rebuild on SQLite.
 */
final class EffortLevelSet
{
    public const MAX_NAMES = 32;
    public const MAX_NAME_LENGTH = 16;
    public const NAME_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /**
     * @param list<string> $names
     */
    private function __construct(private readonly array $names) {}

    /**
     * The set a configuration's `effort_levels` setting describes.
     *
     * The order is the picker's order, so it is preserved; a duplicate keeps
     * the position of its first occurrence.
     *
     * @throws InvalidArgumentException when a name breaks the pattern or the length cap
     */
    public static function fromSetting(string $raw): self
    {
        return self::fromNames(explode(',', $raw));
    }

    /**
     * The set the names describe.
     *
     * A name may arrive as an integer: PHP turns a numeric-string array
     * key into an int, so the levels of a driver that names a level "1"
     * come back as `[1]`. The name is put back into its string form
     * here, because the name is compared against the string the operator
     * wrote.
     *
     * @param list<int|string> $names
     * @throws InvalidArgumentException when a name breaks the pattern or the length cap
     */
    public static function fromNames(array $names): self
    {
        $unique = [];

        foreach ($names as $name) {
            $trimmed = trim((string) $name);

            if ($trimmed === '' || in_array($trimmed, $unique, true)) {
                continue;
            }

            self::assertName($trimmed);
            $unique[] = $trimmed;
        }

        if (count($unique) > self::MAX_NAMES) {
            throw new InvalidArgumentException(sprintf(
                'A reasoning-effort level set may name at most %d levels; %d were given.',
                self::MAX_NAMES,
                count($unique),
            ));
        }

        return new self($unique);
    }

    /**
     * The level names a driver's name => wire value map carries.
     *
     * PHP turns a numeric-string array key into an integer, so the map of
     * a configuration that names a level "1" is keyed by the int 1. The
     * name is put back into its string form here, because it is compared
     * against the string the operator wrote.
     *
     * @param array<int|string, string> $levels
     * @return list<string>
     */
    public static function namesFromMap(array $levels): array
    {
        return array_map(strval(...), array_keys($levels));
    }

    public function isEmpty(): bool
    {
        return $this->names === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * The name => provider wire value map.
     *
     * The wire value is the name verbatim: every provider found sends the
     * level unchanged, and the gateways normalise for us.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        return array_combine($this->names, $this->names);
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names, true);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertName(string $name): void
    {
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The reasoning-effort level "%s" is longer than %d characters.',
                $name,
                self::MAX_NAME_LENGTH,
            ));
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The reasoning-effort level "%s" may only contain letters, digits, dots, dashes and underscores.',
                $name,
            ));
        }
    }
}
