<?php

declare(strict_types=1);

namespace Spora\AgentTemplates\Exceptions;

use RuntimeException;

/**
 * Raised when the importer's post-transaction sanity check fails — typically
 * because the Agent row disappeared between the insert and the final fetch
 * (concurrent delete, FK violation rollback, etc.). Distinct from generic
 * RuntimeException so the controller can map it to a 500 without falling
 * through to the broader UNKNOWN_TEMPLATE handler.
 */
final class AgentImportFailedException extends RuntimeException {}
