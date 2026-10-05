<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\SubAgentTool;

/**
 * Pin the invariant: invoking the SubAgentTool must not modify the agent's
 * tool override row. The DB row is read both before and after the call, and
 * the cryptographic blob must be byte-identical.
 */
it('does not modify the agent_tool_overrides row when the SubAgentTool is invoked', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('preserve@example.com', 'Password1!', 'Preserve');

    $sourceAgent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Source',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $targetAgent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Target',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('handover-preserve'),
        [SubAgentTool::class],
    );
    $configService->putAgentOverride(
        SubAgentTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$targetAgent->id])],
    );

    $rowBefore = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', SubAgentTool::class)
        ->firstOrFail();
    $blobBefore = $rowBefore->getRawOriginal('settings');
    expect($blobBefore)->not->toBe('');

    $handoverService = Mockery::mock(HandoverServiceInterface::class);
    $newTask = new Task();
    $newTask->id = 8888;
    $handoverService->allows('handover')->andReturn($newTask);

    $subAgentService = Mockery::mock(Spora\Services\SubAgentServiceInterface::class);
    $subAgentService->shouldNotReceive('spawn');

    $tool = new SubAgentTool($handoverService, $subAgentService, $configService);

    $source = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $sourceAgent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Original',
        'max_steps'   => 5,
    ]);

    // execute() no longer takes the user id as an argument; the tool reads
    // `PrincipalContext::ownerUserId`, so the context is what supplies the
    // authenticated caller.
    $context = new PrincipalContext(
        principalId: createUserPrincipalPublic($userId),
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    $result = $tool->execute(
        arguments: ['op' => 'handover', 'target_agent_id' => $targetAgent->id, 'prompt' => 'ctx'],
        agentId: $sourceAgent->id,
        taskId: $source->id,
        context: $context,
    );
    expect($result->success)->toBeTrue("Tool rejected valid target: {$result->content}");

    $rowAfter = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', SubAgentTool::class)
        ->firstOrFail();
    expect($rowAfter->getRawOriginal('settings'))->toBe($blobBefore);
});

it('does not wipe the allowlist when the handover is rejected (target not in allowlist)', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('preserve2@example.com', 'Password1!', 'Preserve2');

    $sourceAgent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Source',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $allowedAgent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Allowed',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('handover-preserve'),
        [SubAgentTool::class],
    );
    $configService->putAgentOverride(
        SubAgentTool::class,
        $sourceAgent->id,
        ['allowed_target_agents' => json_encode([$allowedAgent->id])],
    );
    $blobBefore = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', SubAgentTool::class)
        ->firstOrFail()
        ->getRawOriginal('settings');

    $handoverService = Mockery::mock(HandoverServiceInterface::class);
    $handoverService->shouldNotReceive('handover');

    $subAgentService = Mockery::mock(Spora\Services\SubAgentServiceInterface::class);
    $subAgentService->shouldNotReceive('spawn');

    $tool = new SubAgentTool($handoverService, $subAgentService, $configService);

    $source = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $sourceAgent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Original',
        'max_steps'   => 5,
    ]);

    // The caller is authenticated, so the allowlist is the only thing that
    // can reject this call. Without a context the tool would fail earlier
    // on its auth guard and the blob comparison below would prove nothing
    // about the rejection path this test is named for.
    $context = new PrincipalContext(
        principalId: createUserPrincipalPublic($userId),
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    // Target an agent NOT in the allowlist — tool rejects.
    $result = $tool->execute(
        arguments: ['target_agent_id' => 9999, 'prompt' => 'ctx'],
        agentId: $sourceAgent->id,
        taskId: $source->id,
        context: $context,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('not in the allowed_target_agents list');

    $blobAfter = Spora\Models\AgentToolOverride::where('agent_id', $sourceAgent->id)
        ->where('tool_class', SubAgentTool::class)
        ->firstOrFail()
        ->getRawOriginal('settings');
    expect($blobAfter)->toBe($blobBefore);
});
