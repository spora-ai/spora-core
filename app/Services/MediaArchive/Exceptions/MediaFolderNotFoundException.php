<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown when a folder does not exist for the acting principal.
 *
 * A folder that belongs to another principal is reported through this
 * exception, not through a "forbidden" one: the caller has no business
 * knowing that the folder exists, so the answer is the same 404 a
 * non-existent folder gets.
 */
final class MediaFolderNotFoundException extends RuntimeException {}
