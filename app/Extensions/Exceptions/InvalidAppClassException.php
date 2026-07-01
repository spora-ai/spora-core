<?php

declare(strict_types=1);

namespace Spora\Extensions\Exceptions;

use RuntimeException;

/**
 * Thrown when the file resolved as `<BASE_PATH>/app/App.php` exists and
 * declares class(es), but none of them implements
 * {@see \Spora\Extensions\SporaExtensionInterface}.
 *
 * Surfaced by {@see \Spora\Extensions\AppLoader::load()}. Distinct from
 * "no App installed" (which is a silent no-op) — this signals a developer
 * error in the consumer's app/App.php.
 */
final class InvalidAppClassException extends RuntimeException {}
