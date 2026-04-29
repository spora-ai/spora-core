<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Non-destructive .env file writer.
 *
 * Preserves comments and existing formatting, only updates existing keys
 * or appends new ones.
 */
final class DotenvWriter
{
    private const DEFAULT_PATH = __DIR__ . '/../../.env';

    /**
     * Update or append a single KEY=value line.
     *
     * @param string $key   Environment variable name
     * @param string $value New value
     * @param string $path  Path to .env file
     */
    public static function set(string $key, string $value, string $path = self::DEFAULT_PATH): void
    {
        $lines = self::readLines($path);
        $found = false;

        foreach ($lines as $index => $line) {
            if (self::lineMatchesKey($line, $key)) {
                $lines[$index] = self::formatLine($key, $value);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $lines[] = self::formatLine($key, $value);
        }

        self::writeLines($path, $lines);
    }

    /**
     * Batch update multiple key-value pairs.
     *
     * @param array<string, string> $values Key-value pairs to set
     * @param string               $path   Path to .env file
     */
    public static function sets(array $values, string $path = self::DEFAULT_PATH): void
    {
        $lines = self::readLines($path);
        $updatedKeys = [];

        // First pass: update existing keys
        foreach ($lines as $index => $line) {
            foreach ($values as $key => $value) {
                if (self::lineMatchesKey($line, $key)) {
                    $lines[$index] = self::formatLine($key, $value);
                    $updatedKeys[$key] = true;
                    break;
                }
            }
        }

        // Second pass: append new keys not found
        foreach ($values as $key => $value) {
            if (!isset($updatedKeys[$key])) {
                $lines[] = self::formatLine($key, $value);
            }
        }

        self::writeLines($path, $lines);
    }

    /**
     * Check if a line defines the given key.
     */
    private static function lineMatchesKey(string $line, string $key): bool
    {
        $line = trim($line);

        // Skip empty lines and comments
        if ($line === '' || $line[0] === '#') {
            return false;
        }

        // Strip optional "export " prefix
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        // Extract key before '='
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            return false;
        }

        $lineKey = trim(substr($line, 0, $eqPos));

        // Strip quotes from key if present
        if ((str_starts_with($lineKey, '"') && str_ends_with($lineKey, '"'))
            || (str_starts_with($lineKey, "'") && str_ends_with($lineKey, "'"))) {
            $lineKey = substr($lineKey, 1, -1);
        }

        return $lineKey === $key;
    }

    /**
     * Format a line for writing (preserve existing quoting style if found).
     */
    private static function formatLine(string $key, string $value): string
    {
        // Use double quotes if value contains special characters
        if (self::requiresQuoting($value)) {
            return $key . '="' . self::escapeValue($value) . '"';
        }

        return $key . '=' . $value;
    }

    /**
     * Check if value requires quoting.
     */
    private static function requiresQuoting(string $value): bool
    {
        return preg_match('/[\s"\'#$`]/', $value) === 1
            || strpos($value, '==') !== false
            || strpos($value, '!') !== false;
    }

    /**
     * Escape a value for double-quoted output.
     */
    private static function escapeValue(string $value): string
    {
        return preg_replace('/([\\"$])/', '\\\\$1', $value);
    }

    /**
     * Read .env file into lines.
     *
     * @return string[]
     */
    private static function readLines(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);

        // Normalize line endings
        if ($content === false) {
            return [];
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return explode("\n", $content);
    }

    /**
     * Write lines back to .env file with trailing newline.
     *
     * @param string[] $lines
     */
    private static function writeLines(string $path, array $lines): void
    {
        $content = implode("\n", $lines) . "\n";
        file_put_contents($path, $content);
    }
}
