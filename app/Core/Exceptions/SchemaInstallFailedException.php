<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown by DatabaseSchemaInstaller when a migration file does not match the
 * expected naming convention (e.g. plugin migrations must be prefixed with the
 * plugin slug). Indicates a plugin-author mistake, not a runtime failure.
 */
final class SchemaInstallFailedException extends RuntimeException {}
