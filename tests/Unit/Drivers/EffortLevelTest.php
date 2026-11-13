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

/**
 * The label map.
 *
 * The enum is the default vocabulary and the label map, not the
 * constraint on what a level may be called: a configuration names its own
 * levels, and one the vocabulary does not know shows as itself.
 */
test('the canonical names carry a pretty label', function (string $name, string $label): void {
    expect(EffortLevel::labelFor($name))->toBe($label);
})->with([
    ['none', 'None'],
    ['minimal', 'Minimal'],
    ['low', 'Low'],
    ['medium', 'Medium'],
    ['high', 'High'],
    ['xhigh', 'xHigh'],
    ['max', 'Max'],
]);

test('a name the vocabulary does not know shows as itself', function (): void {
    expect(EffortLevel::labelFor('default'))->toBe('default')
        ->and(EffortLevel::labelFor('ultra'))->toBe('ultra');
});

test('a case carries the label of its own name', function (): void {
    foreach (EffortLevel::cases() as $case) {
        expect($case->label())->toBe(EffortLevel::labelFor($case->value));
    }
});
