<?php

declare(strict_types=1);

namespace Spora\Todo;

use RuntimeException;

/**
 * Thrown by a `TodoStore::updateStatus(..., $guard)` callback to reject
 * the proposed write without committing it. The guard runs inside the
 * store's transaction; throwing aborts the row lock and the pending
 * mutation, so the database state is unchanged. `TodoTool` catches this
 * and returns a `ToolResult(false, …)` so the LLM sees a clean error
 * instead of a silent overwrite.
 */
final class TodoGuardException extends RuntimeException {}
