<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\MediaMimeRefinerDiscovery;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;
use Tests\Support\FakeMimeRefiner;

/**
 * {@see MediaMimeRefinerDiscovery} — the third member of the media-archive
 * discovery trio, added for {@see Spora\Services\MediaArchive\MimeSniffer}'s
 * refiner pass. The shared `add()` / `all()` / `reset()` contract is
 * covered once for all three registries in `DiscoversRegistrationsTest`;
 * what is specific here is the guard and the message shape, i.e. that the
 * registry rejects a class that is not a refiner and points the author at
 * the interface they must implement.
 */
beforeEach(function (): void {
    MediaMimeRefinerDiscovery::reset();
    FakeMimeRefiner::reset();
});

afterEach(function (): void {
    MediaMimeRefinerDiscovery::reset();
    FakeMimeRefiner::reset();
});

test('all() is empty until a refiner is registered', function (): void {
    expect(MediaMimeRefinerDiscovery::all())->toBe([]);
});

test('add() registers a refiner FQCN that all() returns in registration order', function (): void {
    $second = new class implements MediaMimeRefinerInterface {
        public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
        {
            return $sniffedMime === 'never-matches' ? 'application/x-second-refiner' : null;
        }
    };

    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    MediaMimeRefinerDiscovery::add($second::class);

    expect(MediaMimeRefinerDiscovery::all())->toBe([FakeMimeRefiner::class, $second::class]);
});

test('add() rejects a class that is not a MediaMimeRefinerInterface', function (): void {
    /** @var string $nonConforming */
    $nonConforming = 'stdClass';

    expect(fn() => MediaMimeRefinerDiscovery::add($nonConforming))
        ->toThrow(
            'MediaMimeRefinerDiscovery::add: stdClass does not implement '
            . MediaMimeRefinerInterface::class,
        );
});

test('add() rejects a refiner-shaped class that implements the wrong interface', function (): void {
    // Guards against the mistake a plugin author is most likely to make:
    // a class with a `refine()` method but no `implements` clause passes
    // duck-typing checks and would break the sniffer's `new $class()`.
    $wrongContract = new class {
        public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
        {
            return $sniffedMime === '' ? 'application/x-nope' : null;
        }
    };
    // Typed as a plain `string` so PHPStan cannot reject the FQCN at the
    // type level — the runtime guard is what is under test.
    /** @var string $wrongContractFqcn */
    $wrongContractFqcn = $wrongContract::class;

    expect(fn() => MediaMimeRefinerDiscovery::add($wrongContractFqcn))
        ->toThrow(InvalidArgumentException::class);
});

test('add() is idempotent', function (): void {
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);

    expect(MediaMimeRefinerDiscovery::all())->toBe([FakeMimeRefiner::class]);
});

test('reset() empties the registry', function (): void {
    MediaMimeRefinerDiscovery::add(FakeMimeRefiner::class);
    expect(MediaMimeRefinerDiscovery::all())->not->toBe([]);

    MediaMimeRefinerDiscovery::reset();
    expect(MediaMimeRefinerDiscovery::all())->toBe([]);
});
