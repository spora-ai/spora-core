<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a transfer references an agent row or a target-principal
 * row that no longer exists. Distinct from {@see UnauthorizedTransferException}
 * (which means the row exists but the caller can't touch it) so the
 * controller layer can map each to a different wire status.
 */
final class TransferTargetNotFoundException extends RuntimeException {}
