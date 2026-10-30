<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown when a write would break a uniqueness rule the service owns:
 * two siblings with the same folder name, or two assets with the same
 * filename in one folder.
 *
 * The database enforces both rules with a UNIQUE index; the service
 * checks them first so the caller gets a domain error instead of a
 * driver-specific constraint violation. A constraint violation that still
 * reaches the service (a concurrent write won the race) is converted into
 * this exception rather than leaking out.
 */
final class MediaFolderUniquenessException extends RuntimeException {}
