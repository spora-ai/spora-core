<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Spora\Agents\ErrorClassifier;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Metering\UsageDecision;

it('maps a hard usage decision to USAGE_LIMIT_REACHED', function (): void {
    $classifier = new ErrorClassifier();

    $decision = new UsageLimitExceededException(new UsageDecision('Quota exhausted.'));

    expect($classifier->classifyError($decision))->toBe('USAGE_LIMIT_REACHED');
});

it('never retries a run that hit a usage limit', function (): void {
    expect(ErrorClassifier::RETRYABLE_ERROR_CODES)->not->toContain('USAGE_LIMIT_REACHED');
});

it('gives the operator a message for a usage limit', function (): void {
    $classifier = new ErrorClassifier();

    $decision = new UsageLimitExceededException(new UsageDecision('Quota exhausted.'));

    expect($classifier->friendlyMessageForError($decision, 'USAGE_LIMIT_REACHED'))
        ->not->toBe($classifier->friendlyMessages()['UNKNOWN']);
});
