<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\HandoverTool;

/**
 * End-to-end check that the real storage path doesn't lose multi-select shape.
 *
 * The frontend sends a multi-select value as a JSON-encoded string (the form
 * is `Record<string, string>`). The backend must decode it back to int[] so
 * `HandoverTool::execute()` can do its allowlist check. This test bypasses
 * the LLM and the orchestrator — it saves a setting through
 * `ToolConfigService::putAgentOverride` and reads it back through
 * `getEffectiveSettings`, then runs the tool's `execute()` directly.
 */

function makeFreshToolConfigService(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('handover-realcfg'),
        [HandoverTool::class],
    );
}

it('decodes a JSON-string multi-select setting on read and accepts a target in the allowlist', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('realcfg@example.com', 'Password1!', 'RealCfg');

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

    // The form layer ships a JSON-encoded string (since the form is
    // `Record<string, string>`). Persist it the same way the controller would.
    $configService = makeFreshToolConfigService();
    $configService->putAgentOverride(
        HandoverTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$targetAgent->id])],
    );

    // Re-read through the same path the HandoverTool uses.
    $effective = $configService->getEffectiveSettings(HandoverTool::class, $sourceAgent->id, $userId);
    expect($effective['allowed_target_agents'])->toBe([$targetAgent->id]);

    $handoverService = Mockery::mock(HandoverServiceInterface::class);
    $newTask = new Task();
    $newTask->id = 999;
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
    expect($result->content)->toContain("Handed over to agent #{$targetAgent->id}");
});

it('still rejects a target NOT in the allowlist when the value is stored as a JSON string', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('realcfg2@example.com', 'Password1!', 'RealCfg2');

    $sourceAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Source2',
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
    $otherAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Other',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = makeFreshToolConfigService();
    $configService->putAgentOverride(
        HandoverTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$allowedAgent->id])],
    );

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

    $result = $tool->execute(
        arguments: ['target_agent_id' => $otherAgent->id, 'context_summary' => 'ctx'],
        agentId: $sourceAgent->id,
        userId: $userId,
        taskId: $source->id,
    );

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('not in the allowed_target_agents list');
});
