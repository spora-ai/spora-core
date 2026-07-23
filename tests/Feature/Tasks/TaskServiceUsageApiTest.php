<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;

/**
 * Verifies the per-message usage exposure on the task detail resource:
 *  - content_blocks round-trips per row
 *  - signature / data / raw_usage / driver_meta_info are stripped
 *  - totals sum the six typed counters across assistant turns
 */
test('task detail resource exposes content_blocks, sanitized usage, and aggregated totals', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('taskusage@example.com', 'Password1!', 'TaskUsage');
    simulateLoggedInSession($userId, 'taskusage@example.com');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'TaskUsageAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'COMPLETED',
        'user_prompt' => 'hi',
        'max_steps' => 5,
    ]);

    $row1 = TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 1,
        'role' => 'assistant',
        'content' => 'first reply',
        'content_blocks' => [
            ['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'first reply'],
        ],
    ]);
    $row2 = TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 2,
        'role' => 'assistant',
        'content' => 'second reply',
        'content_blocks' => [
            ['type' => 'text', 'text' => 'second reply'],
        ],
    ]);

    $now = Carbon::now();
    foreach ([$row1, $row2] as $index => $row) {
        Capsule::table('usage')->insert([
            'task_history_id' => $row->id,
            'input_tokens' => 100,
            'output_tokens' => 50 + $index,
            'reasoning_tokens' => 5,
            'cached_tokens' => 80,
            'cache_creation_tokens' => 4,
            'cache_read_tokens' => 16,
            'provider' => 'openai',
            'raw_usage' => json_encode(['prompt_tokens' => 100, 'secret' => 'do-not-leak']),
            'driver_meta_info' => json_encode(['tier' => 'priority']),
            'created_at' => $now->copy()->addSeconds($index)->toDateTimeString(),
        ]);
    }

    $service = new TaskService(
        Mockery::mock(OrchestratorInterface::class),
        Mockery::mock(MercurePublisherInterface::class),
    );

    /** @var array<string, mixed> $result */
    $result = $service->getTaskWithHistory($task->id, $userId);

    expect($result)->not->toBeNull();
    expect($result['history'])->toHaveCount(2);

    $first = $result['history'][0];
    expect($first['content_blocks'])->toHaveCount(2);
    expect($first['content_blocks'][0])->toMatchArray(['type' => 'thinking', 'text' => 'plan']);
    expect($first['content_blocks'][0])->not->toHaveKey('signature');
    expect($first['content_blocks'][0])->not->toHaveKey('data');
    expect($first['content_blocks'][1]['text'])->toBe('first reply');

    expect($first['usage']['input_tokens'])->toBe(100);
    expect($first['usage']['output_tokens'])->toBe(50);
    expect($first['usage']['cached_tokens'])->toBe(80);
    expect($first['usage']['provider'])->toBe('openai');
    expect($first['usage'])->not->toHaveKey('raw_usage');
    expect($first['usage'])->not->toHaveKey('driver_meta_info');

    $second = $result['history'][1];
    expect($second['content_blocks'])->toHaveCount(1);
    expect($second['content_blocks'][0]['text'])->toBe('second reply');
    expect($second['usage']['output_tokens'])->toBe(51);

    expect($result['totals']['input_tokens'])->toBe(200);
    expect($result['totals']['output_tokens'])->toBe(101);
    expect($result['totals']['cached_tokens'])->toBe(160);
    expect($result['totals']['cache_creation_tokens'])->toBe(8);
    expect($result['totals']['cache_read_tokens'])->toBe(32);
    expect($result['totals'])->not->toHaveKey('provider');
    expect($result['totals'])->not->toHaveKey('raw_usage');
});
