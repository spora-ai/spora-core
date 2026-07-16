<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use RuntimeException;

/**
 * Thrown when the current user tries to act on a media asset they do
 * not own (and are not an admin for). The Orchestrator raises this on
 * non-owner attachment; controllers raise it (or a sibling) on
 * cross-owner PATCH/DELETE.
 *
 * Mapped to HTTP 403 by {@see \Spora\Core\Kernel::mapKnownExceptionToResponse()}.
 */
final class MediaNotOwnedException extends RuntimeException {}
