<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when Database::getCapsule() is called before the connection has been booted.
 * Indicates a boot-order bug — Database::bootDatabaseConnectionOnly() must run first.
 */
final class DatabaseNotBootedException extends RuntimeException {}
