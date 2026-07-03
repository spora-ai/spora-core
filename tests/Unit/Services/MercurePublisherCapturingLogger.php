<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test logger that records every log call for later assertions.
 */
final class MercurePublisherCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
