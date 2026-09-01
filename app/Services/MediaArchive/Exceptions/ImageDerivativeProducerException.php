<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Exceptions;

use RuntimeException;

/**
 * Thrown by image-derivative producers when a preset cannot be rendered.
 *
 * Carries the original exception as `$previous` so the caller can still
 * log the underlying driver / IO failure. The status code that the HTTP
 * controller maps this to (422) is decided in the controller, not here
 * — the exception is content-agnostic.
 */
final class ImageDerivativeProducerException extends RuntimeException {}
