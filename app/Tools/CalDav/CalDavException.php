<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use RuntimeException;

/**
 * Thrown by CalDav helpers (HTTP transport, ICS building/parsing) for
 * conditions that should be reported to the LLM as a user-facing failure.
 *
 * SonarQube S112 requires non-generic exceptions; RuntimeException is the
 * closest generic predecessor we still allow.
 */
final class CalDavException extends RuntimeException {}
