<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use RuntimeException;

/**
 * Thrown by {@see RemoteMediaFetcher} when the upstream CDN returns a
 * non-2xx status, the body is unreachable, or the body overshoots the
 * configured byte cap. Carries the HTTP status (or `0` for transport
 * errors) and the URL so callers can log meaningful diagnostics without
 * having to catch-and-unwrap.
 */
final class RemoteMediaFetchException extends RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
