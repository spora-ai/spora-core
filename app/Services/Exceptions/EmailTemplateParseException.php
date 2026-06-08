<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a YAML email template fails to parse.
 */
final class EmailTemplateParseException extends RuntimeException {}
