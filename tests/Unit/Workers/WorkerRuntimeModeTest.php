<?php

declare(strict_types=1);

use Spora\Agents\ValueObjects\WorkerRuntimeMode;

it('exposes Server and Client cases', function (): void {
    expect(WorkerRuntimeMode::Server->value)->toBe('server');
    expect(WorkerRuntimeMode::Client->value)->toBe('client');
});

it('tryFrom returns null for unknown values', function (): void {
    expect(WorkerRuntimeMode::tryFrom('worker'))->toBeNull();
    expect(WorkerRuntimeMode::tryFrom(''))->toBeNull();
});

it('from throws ValueError for unknown values', function (): void {
    WorkerRuntimeMode::from('unknown');
})->throws(ValueError::class);
