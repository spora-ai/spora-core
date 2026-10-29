<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkDispatcher;
use Spora\Scheduling\DueWorkProviderInterface;
use Spora\Scheduling\DueWorkProviderRegistry;
use Tests\Unit\Scheduling\SecondStubDueWorkProvider;
use Tests\Unit\Scheduling\StubDueWorkProvider;

/**
 * @param list<DueWorkProviderInterface> $providers
 */
function dueWorkDispatcherFor(array $providers): DueWorkDispatcher
{
    return new DueWorkDispatcher(
        new DueWorkProviderRegistry($providers),
        new NullLogger(),
    );
}

function dueWorkUnit(string $provider, int $principalId, string $dueAt, string $id = 'x'): DueWork
{
    return new DueWork(
        provider: $provider,
        principalId: $principalId,
        payload: ['id' => $id],
        dueAt: $dueAt,
    );
}

it('hands the provider the principals the caller may act for', function (): void {
    $provider = new StubDueWorkProvider();

    dueWorkDispatcherFor([$provider])->dispatch([7, 9]);

    expect($provider->claims)->toBe([[7, 9]]);
});

it('executes the work the provider handed back', function (): void {
    $provider = new StubDueWorkProvider();
    $provider->work = [dueWorkUnit($provider::class, 7, '2026-01-01 10:00:00')];

    $executed = dueWorkDispatcherFor([$provider])->dispatch([7]);

    expect($executed)->toBe(1)
        ->and($provider->executed)->toHaveCount(1)
        ->and($provider->executed[0]->payload)->toBe(['id' => 'x']);
});

it('runs the work oldest first', function (): void {
    $provider = new StubDueWorkProvider();
    $provider->work = [
        dueWorkUnit($provider::class, 7, '2026-01-02 10:00:00', 'later'),
        dueWorkUnit($provider::class, 7, '2026-01-01 10:00:00', 'earlier'),
    ];

    dueWorkDispatcherFor([$provider])->dispatch([7]);

    expect(array_map(static fn(DueWork $w): string => (string) $w->payload['id'], $provider->executed))
        ->toBe(['earlier', 'later']);
});

it('a provider that throws while reporting its work does not stop the next one', function (): void {
    $broken = new StubDueWorkProvider();
    $broken->throwOnClaim = true;

    $working = new SecondStubDueWorkProvider();
    $working->work = [dueWorkUnit($working::class, 7, '2026-01-01 10:00:00')];

    $executed = dueWorkDispatcherFor([$broken, $working])->dispatch([7]);

    expect($executed)->toBe(1)
        ->and($working->executed)->toHaveCount(1);
});

it('a failure inside execute() does not abandon the work behind it', function (): void {
    $failing = new StubDueWorkProvider();
    $failing->work = [dueWorkUnit($failing::class, 7, '2026-01-01 10:00:00', 'failing')];
    $failing->throwOnExecute = true;

    $working = new SecondStubDueWorkProvider();
    $working->work = [dueWorkUnit($working::class, 7, '2026-01-02 10:00:00', 'working')];

    $executed = dueWorkDispatcherFor([$failing, $working])->dispatch([7]);

    expect($executed)->toBe(1)
        ->and($failing->executed)->toBe([])
        ->and($working->executed)->toHaveCount(1);
});

it('never executes work belonging to a principal the caller cannot act for', function (): void {
    $provider = new StubDueWorkProvider();
    $provider->work = [
        dueWorkUnit($provider::class, 7, '2026-01-01 10:00:00', 'mine'),
        dueWorkUnit($provider::class, 99, '2026-01-01 10:00:00', 'theirs'),
    ];

    $executed = dueWorkDispatcherFor([$provider])->dispatch([7]);

    expect($executed)->toBe(1)
        ->and($provider->executed)->toHaveCount(1)
        ->and((string) $provider->executed[0]->payload['id'])->toBe('mine');
});

it('a work item naming a provider that is not registered is skipped', function (): void {
    $provider = new StubDueWorkProvider();
    $provider->work = [dueWorkUnit('Some\\Gone\\Provider', 7, '2026-01-01 10:00:00')];

    $executed = dueWorkDispatcherFor([$provider])->dispatch([7]);

    expect($executed)->toBe(0)
        ->and($provider->executed)->toBe([]);
});
