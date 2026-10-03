<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterInterface;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Tests\Support\FakeDerivativeProducer;
use Tests\Support\FakeMimeRefiner;

/**
 * Contract of {@see DiscoversRegistrations} — the scaffolding all three
 * media-archive registries share. Asserting it against every registry at
 * once is what pins the extraction: the two pre-existing registries and
 * the new refiner one must behave identically, so a future fourth
 * registry gets the guard, the idempotence and the message shape for
 * free.
 *
 * Rows are class-strings rather than bound callables because Pest cannot
 * resolve a closure inside a dataset row, and `$registry::add()` on a
 * `class-string` is already type-checked.
 *
 * @return array<string, array{class-string, class-string, class-string}>
 */
dataset('discovery registries', [
    'converter' => [
        MediaConverterDiscovery::class,
        MediaConverterInterface::class,
        PlainTextPassthroughConverter::class,
    ],
    'producer' => [
        MediaDerivativeProducerDiscovery::class,
        MediaDerivativeProducerInterface::class,
        FakeDerivativeProducer::class,
    ],
    'refiner' => [
        MediaMimeRefinerDiscovery::class,
        MediaMimeRefinerInterface::class,
        FakeMimeRefiner::class,
    ],
]);

beforeEach(function (): void {
    MediaConverterDiscovery::reset();
    MediaDerivativeProducerDiscovery::reset();
    MediaMimeRefinerDiscovery::reset();
});

afterEach(function (): void {
    MediaConverterDiscovery::reset();
    MediaDerivativeProducerDiscovery::reset();
    MediaMimeRefinerDiscovery::reset();
});

test('add() is idempotent — three adds yield one entry', function (string $registry, string $interface, string $conforming): void {
    $registry::add($conforming);
    $registry::add($conforming);
    $registry::add($conforming);

    expect($registry::all())->toBe([$conforming]);
})->with('discovery registries');

test('add() throws InvalidArgumentException naming the class and the interface', function (string $registry, string $interface, string $conforming): void {
    // A plain `string` rather than a `class-string<…>` so PHPStan cannot
    // reject the literal at the type level — the runtime guard inside
    // `add()` is the unit under test.
    /** @var string $nonConforming */
    $nonConforming = 'stdClass';

    expect(function () use ($registry, $nonConforming): void {
        $registry::add($nonConforming);
    })->toThrow(sprintf('%s::add: %s does not implement %s', $registry, $nonConforming, $interface));
})->with('discovery registries');

test('a rejected add() leaves the registry untouched', function (string $registry, string $interface, string $conforming): void {
    /** @var string $nonConforming */
    $nonConforming = 'stdClass';

    try {
        $registry::add($nonConforming);
    } catch (InvalidArgumentException) {
        // Guarded by its own test above; here we only care about the
        // half-written state a failed add() could have left behind.
    }

    expect($registry::all())->toBe([]);
})->with('discovery registries');

test('all() is empty before anything is added', function (string $registry, string $interface, string $conforming): void {
    expect($registry::all())->toBe([]);
})->with('discovery registries');

test('reset() empties the registry', function (string $registry, string $interface, string $conforming): void {
    $registry::add($conforming);
    expect($registry::all())->toHaveCount(1);

    $registry::reset();
    expect($registry::all())->toBe([]);
})->with('discovery registries');

/**
 * The reason the scaffolding is a trait and not an abstract base: a
 * `private static` property on a parent is shared by every subclass that
 * does not redeclare it, so an abstract `ClassListDiscovery` would merge
 * all three registries into one list.
 */
test('the three registries do not share storage', function (): void {
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect(MediaConverterDiscovery::all())->toBe([PlainTextPassthroughConverter::class]);
    expect(MediaDerivativeProducerDiscovery::all())->toBe([FakeDerivativeProducer::class]);
    expect(MediaMimeRefinerDiscovery::all())->toBe([FakeMimeRefiner::class]);
});

test('resetting one registry leaves the others intact', function (): void {
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    MediaMimeRefinerDiscovery::reset();

    expect(MediaMimeRefinerDiscovery::all())->toBe([]);
    expect(MediaConverterDiscovery::all())->toBe([PlainTextPassthroughConverter::class]);
    expect(MediaDerivativeProducerDiscovery::all())->toBe([FakeDerivativeProducer::class]);
});
