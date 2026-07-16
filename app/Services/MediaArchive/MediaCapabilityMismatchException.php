<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use RuntimeException;

/**
 * Thrown when a media asset's content (e.g. an image) cannot be consumed
 * by the requesting agent's configured LLM (e.g. the LLM is text-only).
 *
 * Mapped to HTTP 400 in {@see \Spora\Core\Kernel::mapKnownExceptionToResponse()}.
 *
 * Surfaced from {@see \Spora\Http\TaskController::store()} and
 * {@see \Spora\Http\TaskController::continue()} BEFORE the task starts, so
 * the caller gets an actionable error rather than a silent image-strip
 * during the first tick.
 */
final class MediaCapabilityMismatchException extends RuntimeException
{
}