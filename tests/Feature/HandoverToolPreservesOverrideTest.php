<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\HandoverTool;

/**
 * Pin the invariant: invoking the HandoverTool must not modify the agent's
 * tool override row. The DB row is read both before and after the call, and
 * the cryptographic blob must be byte-identical.
 */
it('does not modify the agent_tool_overrides row when the HandoverTool is invoked', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('preserve@example.com', 'Password1!', 'Preserve');

    $sourceAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Source',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $targetAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Target',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('handover-preserve'),
        [HandoverTool::class],
    );
    $configService->putAgentOverride(
        HandoverTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$targetAgent->id])],
    );

    $rowBefore = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', HandoverTool::class)
        ->firstOrFail();
    $blobBefore = $rowBefore->getRawOriginal('settings');
    expect($blobBefore)->not->toBe('');

    $handoverService = Mockery::mock(HandoverServiceInterface::class);
    $newTask = new Task();
    $newTask->id = 8888;
    $handoverService->allows('handover')->andReturn($newTask);
    $tool = new HandoverTool($handoverService, $configService);

    $source = Task::create([
        'user_id'     => $userId,
        'agent_id'    => $sourceAgent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Original',
        'max_steps'   => 5,
    ]);

    $result = $tool->execute(
        arguments: ['target_agent_id' => $targetAgent->id, 'context_summary' => 'ctx'],
        agentId: $sourceAgent->id,
        userId: $userId,
        taskId: $source->id,
    );
    expect($result->success)->toBeTrue("Tool rejected valid target: {$result->content}");

    $rowAfter = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', HandoverTool::class)
        ->firstOrFail();
    expect($rowAfter->getRawOriginal('settings'))->toBe($blobBefore);
});

it('does not wipe the allowlist when the handover is rejected (target not in allowlist)', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('preserve2@example.com', 'Password1!', 'Preserve2');

    $sourceAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Source',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $allowedAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Allowed',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('handover-preserve'),
        [HandoverTool::class],
    );
    $configService->putAgentOverride(
        HandoverTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$allowedAgent->id])],
    );
    $blobBefore = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', HandoverTool::class)
        ->firstOrFail()
        ->getRawOriginal('settings');

    $handoverService = Mockery::mock(HandoverServiceInterface::class);
    $handoverService->shouldNotReceive('handover');
    $tool = new HandoverTool($handoverService, $configService);

    $source = Task::create([
        'user_id'     => $userId,
        'agent_id'    => $sourceAgent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Original',
        'max_steps'   => 5,
    ]);

    // Target an agent NOT in the allowlist — tool rejects.
    $result = $tool->execute(
        arguments: ['target_agent_id' => 9999, 'context_summary' => 'ctx'],
        agentId: $sourceAgent->id,
        userId: $userId,
        taskId: $source->id,
    );
    expect($result->success)->toBeFalse();

    $blobAfter = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', HandoverTool::class)
        ->firstOrFail()
        ->getRawOriginal('settings');
    expect($blobAfter)->toBe($blobBefore);
});
