<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown when a folder write is structurally impossible: an empty name, a
 * name that carries a path separator, a move that would put a folder
 * inside its own subtree.
 */
final class MediaFolderValidationException extends RuntimeException {}
