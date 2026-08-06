<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\HandoverTool;

const HANDOVER_AGENT_ID = 1;
const HANDOVER_USER_ID  = 42;
const HANDOVER_TASK_ID  = 100;
const HANDOVER_TARGET_AGENT = 5;
const HANDOVER_NEW_TASK_ID  = 999;
const HANDOVER_SUB_CHILD_ID  = 777;

/**
 * @return array{0: HandoverTool, 1: HandoverServiceInterface&MockInterface, 2: SubAgentServiceInterface&MockInterface, 3: ToolConfigServiceInterface&MockInterface}
 */
function makeHandoverTool(): array
{
    $handover = Mockery::mock(HandoverServiceInterface::class);
    $subAgent = Mockery::mock(SubAgentServiceInterface::class);
    $config   = Mockery::mock(ToolConfigServiceInterface::class);

    return [new HandoverTool($handover, $subAgent, $config), $handover, $subAgent, $config];
}

describe('HandoverTool::execute (handover op)', function (): void {

    test('returns failure when target_agent_id is missing', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute([], HANDOVER_AGENT_ID, HANDOVER_USER_ID, HANDOVER_TASK_ID);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
    });

    test('returns failure when prompt is missing', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('prompt is required.');
    });

    test('returns failure when userId is null', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            null,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('returns failure when taskId is null', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('current task context');
    });

    test('returns failure when target is not in the allowlist', function (): void {
        [$tool, $handover, , $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [2, 3]]);

        $result = $tool->execute(
            ['target_agent_id' => 1, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('returns failure when service throws InvalidArgumentException', function (): void {
        [$tool, $handover, , $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);
        $handover->allows('handover')
            ->andThrow(new InvalidArgumentException('Source task not found.'));

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('Source task not found.');
    });

    test('returns success with new_task_id and target_agent_id on happy path', function (): void {
        [$tool, $handover, , $config] = makeHandoverTool();
        $newTask = new Task();
        $newTask->id = HANDOVER_NEW_TASK_ID;
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);
        $handover->allows('handover')
            ->with(HANDOVER_TASK_ID, HANDOVER_TARGET_AGENT, 'ctx', HANDOVER_USER_ID)
            ->andReturn($newTask);

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['op'])->toBe('handover')
            ->and($result->data['handover'])->toBeTrue()
            ->and($result->data['new_task_id'])->toBe(HANDOVER_NEW_TASK_ID)
            ->and($result->data['target_agent_id'])->toBe(HANDOVER_TARGET_AGENT)
            // The content is rendered as markdown in the chat UI, so the
            // "New task #N" reference is a clickable link to the new task.
            ->and($result->content)->toContain("Task delegated to agent #" . HANDOVER_TARGET_AGENT)
            ->and($result->content)->toContain("[New task #" . HANDOVER_NEW_TASK_ID . "](/tasks/" . HANDOVER_NEW_TASK_ID . ")");
    });
});

describe('HandoverTool::execute (sub_agent op)', function (): void {

    test('routes to SubAgentService with the right args', function (): void {
        [$tool, , $subAgent, $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);

        $child = new Task();
        $child->id = HANDOVER_SUB_CHILD_ID;
        $subAgent->allows('spawn')
            ->with(HANDOVER_TASK_ID, HANDOVER_TARGET_AGENT, 'do the thing', HANDOVER_USER_ID)
            ->andReturn($child);

        $result = $tool->execute(
            ['op' => 'sub_agent', 'agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'do the thing'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['op'])->toBe('sub_agent')
            ->and($result->data['spawned_sub_task_ids'])->toBe([HANDOVER_SUB_CHILD_ID])
            ->and($result->data['target_agent_id'])->toBe(HANDOVER_TARGET_AGENT)
            ->and($result->content)->toContain('Sub-agent task #' . HANDOVER_SUB_CHILD_ID);
    });

    test('returns failure when agent_id is missing', function (): void {
        [$tool, , $subAgent] = makeHandoverTool();

        $result = $tool->execute(
            ['op' => 'sub_agent', 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('agent_id is required.');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when prompt is missing', function (): void {
        [$tool, , $subAgent] = makeHandoverTool();

        $result = $tool->execute(
            ['op' => 'sub_agent', 'agent_id' => HANDOVER_TARGET_AGENT],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('prompt is required.');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when target is not in the allowlist', function (): void {
        [$tool, , $subAgent, $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [2, 3]]);

        $result = $tool->execute(
            ['op' => 'sub_agent', 'agent_id' => 1, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when SubAgentService throws InvalidArgumentException', function (): void {
        [$tool, , $subAgent, $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);
        $subAgent->allows('spawn')
            ->andThrow(new InvalidArgumentException('Parent task not found.'));

        $result = $tool->execute(
            ['op' => 'sub_agent', 'agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('Parent task not found.');
    });
});

describe('HandoverTool::describeAction', function (): void {

    test('renders the target agent id for the handover op', function (): void {
        [$tool] = makeHandoverTool();

        expect($tool->describeAction(['target_agent_id' => HANDOVER_TARGET_AGENT]))
            ->toBe('Hand over the task to agent #' . HANDOVER_TARGET_AGENT . '.');
    });

    test('renders the agent id for the sub_agent op', function (): void {
        [$tool] = makeHandoverTool();

        expect($tool->describeAction(['op' => 'sub_agent', 'agent_id' => HANDOVER_TARGET_AGENT]))
            ->toBe('Spawn a sub-agent on agent #' . HANDOVER_TARGET_AGENT . ' and wait for its result.');
    });
});

describe('HandoverTool::getParametersSchema', function (): void {

    test('requires the op discriminator and pin-points per-op required params', function (): void {
        [$tool] = makeHandoverTool();

        $schema = $tool->getParametersSchema();

        expect($schema['required'])->toContain('op')
            ->and($schema['required'])->toContain('prompt')
            ->and($schema['properties']['op']['enum'])->toBe(['handover', 'sub_agent'])
            ->and($schema['properties']['target_agent_id'])->toBeArray()
            ->and($schema['properties']['agent_id'])->toBeArray()
            ->and($schema['properties']['prompt'])->toBeArray();
    });
});
