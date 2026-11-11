<?php

declare(strict_types=1);

use Spora\Drivers\EffortLevel;

test('the scale carries every level in ascending order', function (): void {
    expect(array_column(EffortLevel::cases(), 'value'))
        ->toBe(['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max']);
});

test('tryFrom parses a level value', function (): void {
    expect(EffortLevel::tryFrom('none'))->toBe(EffortLevel::None)
        ->and(EffortLevel::tryFrom('minimal'))->toBe(EffortLevel::Minimal)
        ->and(EffortLevel::tryFrom('low'))->toBe(EffortLevel::Low)
        ->and(EffortLevel::tryFrom('medium'))->toBe(EffortLevel::Medium)
        ->and(EffortLevel::tryFrom('high'))->toBe(EffortLevel::High)
        ->and(EffortLevel::tryFrom('xhigh'))->toBe(EffortLevel::XHigh)
        ->and(EffortLevel::tryFrom('max'))->toBe(EffortLevel::Max);
});

test('tryFrom rejects a value that is not a level', function (): void {
    expect(EffortLevel::tryFrom('ultra'))->toBeNull()
        ->and(EffortLevel::tryFrom(''))->toBeNull()
        ->and(EffortLevel::tryFrom('HIGH'))->toBeNull();
});
