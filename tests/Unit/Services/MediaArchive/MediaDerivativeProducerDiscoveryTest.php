<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

beforeEach(function (): void {
    // The registry is a static, process-global list — without this
    // reset, a sibling test in the same parallel worker (e.g. the
    // options controller registering `ImageDerivativeProducer`) leaks
    // into this file and breaks the strict "exactly one entry"
    // assertion below.
    MediaDerivativeProducerDiscovery::reset();
});

test('MediaDerivativeProducerDiscovery::add is idempotent', function (): void {
    MediaDerivativeProducerDiscovery::add(Tests\Support\FakeDerivativeProducer::class);
    MediaDerivativeProducerDiscovery::add(Tests\Support\FakeDerivativeProducer::class);
    MediaDerivativeProducerDiscovery::add(Tests\Support\FakeDerivativeProducer::class);

    expect(MediaDerivativeProducerDiscovery::all())->toBe([Tests\Support\FakeDerivativeProducer::class]);
});

test('MediaDerivativeProducerDiscovery::add throws when the class does not implement the interface', function (): void {
    // Use a local class string typed as `string` (not
    // `class-string<…>`) so PHPStan can't reject the literal at the
    // type-system level — the runtime check inside `add()` is the
    // unit under test.
    /** @var string $nonConforming */
    $nonConforming = 'stdClass';
    expect(fn() => MediaDerivativeProducerDiscovery::add($nonConforming))
        ->toThrow(InvalidArgumentException::class);
});

test('MediaDerivativeProducerDiscovery::reset clears the registered list', function (): void {
    MediaDerivativeProducerDiscovery::add(Tests\Support\FakeDerivativeProducer::class);
    expect(MediaDerivativeProducerDiscovery::all())->toHaveCount(1);

    MediaDerivativeProducerDiscovery::reset();
    expect(MediaDerivativeProducerDiscovery::all())->toBe([]);
});
