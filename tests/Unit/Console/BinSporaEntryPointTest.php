<?php

declare(strict_types=1);

/**
 * Validates the contract of the framework's bin/spora:
 *   - Errors with a clear message if BASE_PATH is undefined.
 *   - Loads vendor/autoload.php once BASE_PATH is defined.
 *
 * The framework no longer guesses the consumer root via reflection.
 * Each entry point (consumer's bin/spora stub, public/index.php, tests/Pest.php
 * in dev) is responsible for defining BASE_PATH via `dirname(__FILE__, 2)`.
 */

it('bin/spora errors with a clear message when BASE_PATH is not defined', function (): void {
    $binSpora = file_get_contents(__DIR__ . '/../../../bin/spora');

    // Sanity: the file exists and declares strict types.
    expect($binSpora)->not->toBeFalse();
    expect($binSpora)->toContain('declare(strict_types=1)');

    // The contract: a runtime guard that errors when BASE_PATH is undefined.
    expect($binSpora)->toContain("!defined('BASE_PATH')")
        ->and($binSpora)->toContain('BASE_PATH is not defined');

    // And it actually loads the autoloader using BASE_PATH — proving
    // BASE_PATH is treated as the consumer root.
    expect($binSpora)->toContain("require_once BASE_PATH . '/vendor/autoload.php'");
});
