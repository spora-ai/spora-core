<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Tools\AskUserQuestionTool;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * A question tool whose operation the operator configured to need approval.
 *
 * The approval requirement is the operator's policy; a question call that
 * ignores it asks the operator a question the policy never allowed.
 */
function questionApprovalAgent(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('question-approval@example.com', TEST_PASSWORD, 'Question');

    $config = LLMDriverConfiguration::create([
        'principal_id'       => null,
        'name'               => 'Question Approval Config',
        'driver_class'       => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'           => json_encode(['api_key' => 'test']),
        'is_global'          => true,
        'is_default'         => true,
        'context_window'     => 128000,
        'max_tokens_output'  => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'Question Approval Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 10,
        'is_active'          => true,
    ]);

    AgentTool::insert([
        'agent_id'   => $agent->id,
        'tool_class' => AskUserQuestionTool::class,
        'tool_name'  => 'ask_user_question',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    AgentToolOperationOverride::create([
        'agent_id'                  => $agent->id,
        'tool_class'                => AskUserQuestionTool::class,
        'operation'                 => 'ask',
        'enabled'                   => 1,
        'default_requires_approval' => 1,
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'ask me something',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    return [$agent->id, $task->id];
}

function questionApprovalOrchestrator(): Orchestrator
{
    $driver = Mockery::mock(Spora\Drivers\LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(new Spora\Drivers\ValueObjects\LLMResponse(
        content: null,
        toolCalls: [new DriverToolCall(
            'call_question',
            'ask_user_question',
            ['questions' => [[
                'question' => 'Ship it?',
                'header'   => 'Ship',
                'options'  => [['label' => 'Yes', 'description' => 'Ship it']],
            ]]],
        )],
        inputTokens: 10,
        outputTokens: 5,
        completionId: 'cmp_1',
    ));
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator($factory, new OrchestratorConfig(toolInstances: [new AskUserQuestionTool()]));
}

it('waits for approval when the operator configured the question operation to need it', function (): void {
    [$agentId, $taskId] = questionApprovalAgent();

    $orchestrator = questionApprovalOrchestrator();
    $orchestrator->tick($taskId);

    $task = Task::find($taskId);

    // Not AWAITING_INPUT: the question has not been asked yet. The call
    // waits for the operator's approval, like any other tool call.
    expect($task->status)->toBe('PENDING_APPROVAL');

    $row = Spora\Models\ToolCall::where('task_id', $taskId)->first();
    expect($row->status)->toBe('PENDING_APPROVAL');
});
