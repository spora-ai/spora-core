<?php

declare(strict_types=1);

namespace Spora\Build;

use JsonException;

/**
 * Where the catalogues live and how they are written, shared by
 * `i18n:extract` and `i18n:check` so the two can never disagree about the
 * path or the encoding.
 */
final class CatalogFiles
{
    /**
     * The repository root. `bin/spora-build` deliberately skips the Kernel
     * boot, so `BASE_PATH` is usually undefined here and the working
     * directory is the only thing to go on — same fallback
     * {@see \Spora\Console\Commands\OpenApiGenerateCommand} uses.
     */
    public static function basePath(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : getcwd();

        return rtrim((string) $base, '/');
    }

    public static function englishPath(): string
    {
        return self::basePath() . '/locales/en.json';
    }

    public static function pathFor(string $locale): string
    {
        return self::basePath() . '/locales/' . $locale . '.json';
    }

    /**
     * A flat catalogue as pretty-printed JSON, sorted by key so a
     * regenerated file diffs cleanly against the committed one.
     *
     * @param array<string, string> $messages
     *
     * @throws JsonException when a value is not valid UTF-8.
     */
    public static function encode(array $messages): string
    {
        ksort($messages);

        return (string) json_encode(
            $messages,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * A catalogue as a flat `string => string` map. A non-string value is
     * dropped rather than coerced: a catalogue someone hand-edited into the
     * wrong shape should leave its key visibly missing, not render the
     * literal word "Array".
     *
     * @return array<string, string>
     *
     * @throws JsonException when the file is not readable JSON.
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $messages = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }
}
