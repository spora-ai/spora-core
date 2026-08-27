<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a principal row cannot be materialised for a user or a
 * group (idempotent create path exhausted its retries). The base
 * RuntimeException conveys the operational failure; we wrap it so callers
 * can distinguish "principal write lost a race" from a generic error.
 */
final class PrincipalMaterialisationException extends RuntimeException {}
