<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Services\MercurePublisherInterface;

/**
 * In-memory Mercure publisher used by tests.
 *
 * Records every publish / publishToUser call so the test can later
 * assert on the captured payloads.
 */
final class TestCapturingMercure implements MercurePublisherInterface
{
    /** @var list<array{taskId: int, userId: int, data: array<string, mixed>}> */
    public array $taskEvents = [];

    /** @var list<array{userId: int, data: array<string, mixed>}> */
    public array $userEvents = [];

    public function publish(int $taskId, int $userId, array $taskData): bool
    {
        $this->taskEvents[] = [
            'taskId' => $taskId,
            'userId' => $userId,
            'data'   => $taskData,
        ];
        return true;
    }

    public function publishToUser(int $userId, array $data): bool
    {
        $this->userEvents[] = [
            'userId' => $userId,
            'data'   => $data,
        ];
        return true;
    }
}
