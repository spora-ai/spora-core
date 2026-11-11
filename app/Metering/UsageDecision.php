<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * The meter's answer to "may this call proceed?".
 *
 * The core interprets nothing beyond hard vs soft. `scope` is an opaque key
 * the meter chose — `month:credits`, `rolling:total_tokens` — and passes
 * through untouched, so the vocabulary of windows and units stays with the
 * plugin that owns the price book. `reason` is the sentence the operator
 * reads: it becomes the task's `failure_reason` and `error_message`.
 */
final readonly class UsageDecision
{
    public const MODE_HARD = 'hard';
    public const MODE_SOFT = 'soft';

    public function __construct(
        public string $reason,
        public string $mode = self::MODE_HARD,
        public string $scope = '',
    ) {}

    /** Only a hard decision stops a run; a soft one lets the call proceed, and the core records neither. */
    public function isHard(): bool
    {
        return $this->mode === self::MODE_HARD;
    }
}
