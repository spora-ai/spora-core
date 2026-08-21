<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an injection point is required but unwired (e.g. an
 * AgentService constructed without a PrincipalService). Indicates a
 * factory mis-configuration or a missing test-mode wiring.
 */
final class DependencyNotWiredException extends RuntimeException {}
