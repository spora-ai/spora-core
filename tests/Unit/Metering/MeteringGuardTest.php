<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\LlmSubject;
use Spora\Metering\LlmUsage;
use Spora\Metering\MeteringContext;
use Spora\Metering\MeteringGuard;
use Spora\Metering\NullUsageMeter;
use Spora\Metering\ToolSubject;
use Spora\Metering\ToolUsage;
use Spora\Metering\UsageDecision;

/**
 * The guard is the fail-open rule: a meter that throws is logged and
 * ignored, because a broken price book must not take the platform down
 * mid-run.
 */
it('lets the call proceed when the meter throws while evaluating', function (): void {
    $meter = new StubUsageMeter();
    $meter->evaluateThrows = new RuntimeException('the price book is corrupt');

    $decision = (new MeteringGuard($meter, new NullLogger()))->evaluate(
        new MeteringContext(1, 2, 3, 4, false),
        new LlmSubject('openai_compatible', 'gpt-4o'),
    );

    expect($decision)->toBeNull();
});

it('swallows a meter that throws while recording', function (): void {
    $meter = new StubUsageMeter();
    $meter->recordThrows = new RuntimeException('the ledger table is gone');

    $guard = new MeteringGuard($meter, new NullLogger());
    $context = new MeteringContext(1, 2, 3, 4, false);

    $guard->recordLlmUsage($context, new LlmUsage(new Usage(), 'openai_compatible', 'gpt-4o'));
    $guard->recordToolUsage($context, new ToolUsage('calculator', 'Spora\Tools\CalculatorTool', 'default', true));

    expect($meter->llmUsage)->toHaveCount(1)
        ->and($meter->toolUsage)->toHaveCount(1);
});

it('passes a soft decision through without treating it as a block', function (): void {
    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Halfway to the monthly cap.', UsageDecision::MODE_SOFT, 'month:credits');

    $decision = (new MeteringGuard($meter, new NullLogger()))->evaluate(
        new MeteringContext(1, 2, 3, 4, false),
        new ToolSubject('calculator', 'Spora\Tools\CalculatorTool', 'default'),
    );

    expect($decision)->not->toBeNull()
        ->and($decision->isHard())->toBeFalse();
});

it('reports no meter as inactive so the caller can skip the work a meter would need', function (): void {
    expect((new MeteringGuard(new NullUsageMeter(), new NullLogger()))->isActive())->toBeFalse()
        ->and((new MeteringGuard(new StubUsageMeter(), new NullLogger()))->isActive())->toBeTrue();
});
