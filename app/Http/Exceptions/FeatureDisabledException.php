<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by an admin-gated route whose backing feature is currently disabled.
 *
 * The Kernel maps this to a `403 FEATURE_DISABLED` JSON response — distinct
 * from `FORBIDDEN` so the UI can render an operator-facing message ("this
 * feature is off; set SPORA_* to enable it") instead of a generic "you are
 * not allowed" error.
 */
final class FeatureDisabledException extends RuntimeException {}
