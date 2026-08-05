<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a {@see \Spora\Models\MailTemplate} lookup by name fails.
 * Distinct from generic {@see RuntimeException} so callers (especially the
 * preview controller's Throwable catch) can branch on intent.
 */
final class MailTemplateNotFoundException extends RuntimeException {}
