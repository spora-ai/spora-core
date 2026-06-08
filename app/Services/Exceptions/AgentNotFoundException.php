<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a service cannot find the requested agent
 * (e.g. the agent does not exist or does not belong to the user).
 */
final class AgentNotFoundException extends RuntimeException {}
