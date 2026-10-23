<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when a compaction could not produce a summary.
 *
 * The history is left untouched when this is thrown: a compaction that
 * replaces the summarized turns with nothing would take the conversation's
 * context with it.
 */
final class CompactionFailedException extends RuntimeException {}
