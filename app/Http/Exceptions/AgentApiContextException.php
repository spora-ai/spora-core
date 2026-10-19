<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when the agent an Agent API key belongs to has no resolvable owner
 * principal — a chat created for it would have no `trigger_user_id` to
 * attribute the run to.
 *
 * Its own type because the caller answers a 500 on it, and a reader hunting
 * for the cause should not have to know that "some RuntimeException" is the
 * only one this path can throw.
 */
final class AgentApiContextException extends RuntimeException {}
