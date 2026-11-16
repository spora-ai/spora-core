<?php

declare(strict_types=1);

use Spora\Drivers\EffortLevelSet;

/**
 * The level set one LLM configuration offers.
 *
 * The set is the operator's to write, so the parsing rules are the ones
 * that make a hand-written list usable: the entries are trimmed, the
 * blanks and the repeats are dropped, and the order the operator wrote
 * is the order the picker shows.
 */
test('a setting is split on commas and every entry is trimmed', function (): void {
    expect(EffortLevelSet::fromSetting(' low , medium ,high')->names())
        ->toBe(['low', 'medium', 'high']);
});

test('the blank entries are dropped', function (): void {
    expect(EffortLevelSet::fromSetting('low,,  ,medium')->names())
        ->toBe(['low', 'medium']);
});

test('a repeated name keeps the position of its first occurrence', function (): void {
    expect(EffortLevelSet::fromSetting('low, high, low, medium, high')->names())
        ->toBe(['low', 'high', 'medium']);
});

test('the order is the order the names were written in', function (): void {
    expect(EffortLevelSet::fromSetting('xhigh, none, max')->names())
        ->toBe(['xhigh', 'none', 'max']);
});

test('an empty setting is the empty set, which means the driver\'s own set applies', function (): void {
    $set = EffortLevelSet::fromSetting('');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->names())->toBe([]);
});

test('a name longer than the cap is refused', function (): void {
    expect(fn() => EffortLevelSet::fromSetting(str_repeat('a', EffortLevelSet::MAX_NAME_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class);
});

test('a name of exactly the cap is accepted', function (): void {
    $name = str_repeat('a', EffortLevelSet::MAX_NAME_LENGTH);

    expect(EffortLevelSet::fromSetting($name)->names())->toBe([$name]);
});

test('a name that breaks the pattern is refused', function (string $name): void {
    expect(fn() => EffortLevelSet::fromSetting($name))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'a space' => 'low high',
    'a slash' => 'low/high',
    'a quote' => 'low"high',
    'a comma-free newline' => "low\nhigh",
]);

test('the names the pattern allows are accepted', function (): void {
    expect(EffortLevelSet::fromSetting('none,xhigh,max_thinking,high-reasoning,v1.5')->names())
        ->toBe(['none', 'xhigh', 'max_thinking', 'high-reasoning', 'v1.5']);
});

test('more names than the cap is refused', function (): void {
    $names = [];
    for ($i = 0; $i <= EffortLevelSet::MAX_NAMES; $i++) {
        $names[] = 'level' . $i;
    }

    expect(fn() => EffortLevelSet::fromSetting(implode(',', $names)))
        ->toThrow(InvalidArgumentException::class);
});

test('exactly the cap of names is accepted', function (): void {
    $names = [];
    for ($i = 0; $i < EffortLevelSet::MAX_NAMES; $i++) {
        $names[] = 'level' . $i;
    }

    expect(EffortLevelSet::fromSetting(implode(',', $names))->names())->toHaveCount(EffortLevelSet::MAX_NAMES);
});

test('the wire value of a name is the name itself', function (): void {
    expect(EffortLevelSet::fromSetting('low,default')->map())
        ->toBe(['low' => 'low', 'default' => 'default']);
});

test('the map of the empty set is empty', function (): void {
    expect(EffortLevelSet::fromSetting('')->map())->toBe([]);
});

test('has knows the names the set carries', function (): void {
    $set = EffortLevelSet::fromSetting('low, high');

    expect($set->has('low'))->toBeTrue()
        ->and($set->has('high'))->toBeTrue()
        ->and($set->has('medium'))->toBeFalse()
        ->and($set->has('Low'))->toBeFalse();
});

test('the empty set carries nothing', function (): void {
    $set = EffortLevelSet::fromSetting('');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->has('low'))->toBeFalse();
});

test('the names of a set are the ones it carries', function (): void {
    expect(EffortLevelSet::fromNames(['low', 'high'])->names())->toBe(['low', 'high'])
        ->and(EffortLevelSet::fromNames([])->isEmpty())->toBeTrue();
});

test('a level named with digits keeps its name, whatever an array key does to it', function (): void {
    // PHP turns a numeric-string array key into an int, so the levels of
    // a driver that names a level "1" arrive as [1] rather than ["1"].
    expect(EffortLevelSet::fromNames([1, 2])->names())->toBe(['1', '2']);
});

test('the names of a driver map are strings, whatever the keys are', function (): void {
    expect(EffortLevelSet::namesFromMap([1 => '1', 2 => '2']))->toBe(['1', '2'])
        ->and(EffortLevelSet::namesFromMap(['low' => 'low']))->toBe(['low']);
});
