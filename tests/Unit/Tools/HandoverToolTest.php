<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\HandoverTool;
use Spora\Tools\ValueObjects\ToolResult;

const HANDOVER_AGENT_ID = 1;
const HANDOVER_USER_ID  = 42;
const HANDOVER_TASK_ID  = 100;
const HANDOVER_TARGET_AGENT = 5;
const HANDOVER_NEW_TASK_ID  = 999;

/**
 * @return array{0: HandoverTool, 1: HandoverServiceInterface&MockInterface, 2: ToolConfigServiceInterface&MockInterface}
 */
function makeHandoverTool(): array
{
    $handover = Mockery::mock(HandoverServiceInterface::class);
    $config   = Mockery::mock(ToolConfigServiceInterface::class);

    return [new HandoverTool($handover, $config), $handover, $config];
}

describe('HandoverTool::execute', function (): void {

    test('returns failure when target_agent_id is missing', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute([], HANDOVER_AGENT_ID, HANDOVER_USER_ID, HANDOVER_TASK_ID);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
    });

    test('returns failure when context_summary is missing', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('context_summary is required.');
    });

    test('returns failure when userId is null', function (): void {
        [$tool] = makeHandoverTool();

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'context_summary' => 'ctx'],
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
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'context_summary' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('current task context');
    });

    test('returns failure when target is not in the allowlist', function (): void {
        [$tool, $handover, $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [2, 3]]);

        $result = $tool->execute(
            ['target_agent_id' => 1, 'context_summary' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('returns failure when service throws InvalidArgumentException', function (): void {
        [$tool, $handover, $config] = makeHandoverTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);
        $handover->allows('handover')
            ->andThrow(new InvalidArgumentException('Source task not found.'));

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'context_summary' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('Source task not found.');
    });

    test('returns success with new_task_id and target_agent_id on happy path', function (): void {
        [$tool, $handover, $config] = makeHandoverTool();
        $newTask = new Task();
        $newTask->id = HANDOVER_NEW_TASK_ID;
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);
        $handover->allows('handover')
            ->with(HANDOVER_TASK_ID, HANDOVER_TARGET_AGENT, 'ctx', HANDOVER_USER_ID)
            ->andReturn($newTask);

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'context_summary' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['handover'])->toBeTrue()
            ->and($result->data['new_task_id'])->toBe(HANDOVER_NEW_TASK_ID)
            ->and($result->data['target_agent_id'])->toBe(HANDOVER_TARGET_AGENT);
    });
});

describe('HandoverTool::describeAction', function (): void {
    test('renders the target agent id', function (): void {
        [$tool] = makeHandoverTool();

        expect($tool->describeAction(['target_agent_id' => HANDOVER_TARGET_AGENT]))
            ->toBe('Hand over the current chat to agent #' . HANDOVER_TARGET_AGENT . '.');
    });
});
