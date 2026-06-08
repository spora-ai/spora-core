<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when SseController attempts to publish an SSE update but
 * the Mercure publisher hasn't been configured (missing SPORA_MERCURE_URL
 * or SPORA_MERCURE_JWT_SECRET at boot).
 */
final class MercureConfigurationMissingException extends RuntimeException {}
