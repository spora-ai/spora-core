<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\Models\Agent;
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

/**
 * Seed real `agents` rows for HANDOVER_AGENT_ID and HANDOVER_TARGET_AGENT
 * under a shared user-principal. `HandoverTool::isTargetAllowed()` now
 * cross-checks `principal_id` after the allowlist hit, so any test that
 * exercises the tool past the allowlist needs both agents to exist with
 * the same principal in the (in-memory) DB.
 */
function seedHandoverAgents(int $userId = HANDOVER_USER_ID): int
{
    $principalId = createUserPrincipalPublic($userId);
    $now = date('Y-m-d H:i:s');
    foreach ([HANDOVER_AGENT_ID, HANDOVER_TARGET_AGENT] as $i => $agentId) {
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => $agentId],
            [
                'principal_id' => $principalId,
                'name'         => $i === 0 ? 'Handover Source Agent' : 'Handover Target Agent',
                'max_steps'    => $i === 0 ? 10 : 7,
                'is_active'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        );
    }

    return $principalId;
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
        seedHandoverAgents();
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
        seedHandoverAgents();
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
        seedHandoverAgents();
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
        seedHandoverAgents();
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

describe('HandoverTool back-compat: single-op agents may omit `op`', function (): void {

    test('OperationSchemaFilter::filter strips `op` from required[] when only handover is allowed', function (): void {
        [$tool] = makeHandoverTool();
        $schema = $tool->getParametersSchema();

        $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['handover'], 'op');

        expect($filtered['required'])->not->toContain('op')
            ->and($filtered['properties']['op']['enum'])->toBe(['handover']);
    });

    test('OperationSchemaFilter::filter keeps `op` in required[] when both ops are allowed', function (): void {
        [$tool] = makeHandoverTool();
        $schema = $tool->getParametersSchema();

        $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['handover', 'sub_agent'], 'op');

        expect($filtered['required'])->toContain('op');
    });

    test('SchemaValidator accepts a handover call without `op` when the op is unambiguous', function (): void {
        [$tool] = makeHandoverTool();
        $schema = $tool->getParametersSchema();

        // Mirrors the runtime call site: SchemaValidator::validate($args, $schema, $operationName).
        // HasOperations::getOperationName() resolves the missing op to the first declared op.
        Spora\Agents\SchemaValidator::validate(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'x'],
            $schema,
            'handover',
        );

        expect(true)->toBeTrue(); // no exception was thrown
    });

    test('SchemaValidator rejects a sub_agent call with handover params', function (): void {
        [$tool] = makeHandoverTool();
        $schema = $tool->getParametersSchema();

        // sub_agent requires `agent_id`; supplying `target_agent_id` instead is a
        // wrong-param-for-op case the validator must catch.
        expect(fn() => Spora\Agents\SchemaValidator::validate(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'x'],
            $schema,
            'sub_agent',
        ))->toThrow(InvalidArgumentException::class, "Required argument 'agent_id'");
    });
});

describe('HandoverTool::isTargetAllowed (intra-principal defense in depth)', function (): void {

    test('returns false when source and target belong to different principals even though the target id is in the stored allowlist', function (): void {
        [$tool, $handover, , $config] = makeHandoverTool();

        // Source owned by user A, target owned by user B — different principals.
        $principalA = createUserPrincipalPublic(HANDOVER_USER_ID);
        $otherUserId = HANDOVER_USER_ID + 100;
        $principalB = createUserPrincipalPublic($otherUserId);

        $now = date('Y-m-d H:i:s');
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => HANDOVER_AGENT_ID],
            ['principal_id' => $principalA, 'name' => 'Source A', 'max_steps' => 10, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => HANDOVER_TARGET_AGENT],
            ['principal_id' => $principalB, 'name' => 'Target B', 'max_steps' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        // Stored allowlist contains the foreign id — tampered payload / copy-paste.
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('returns false when the source agent cannot be loaded (fail-closed)', function (): void {
        [$tool, $handover, , $config] = makeHandoverTool();
        // Only the target is seeded; the source agent does NOT exist in the DB.
        $principalId = createUserPrincipalPublic(HANDOVER_USER_ID);
        $now = date('Y-m-d H:i:s');
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => HANDOVER_TARGET_AGENT],
            ['principal_id' => $principalId, 'name' => 'Target Only', 'max_steps' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [HANDOVER_TARGET_AGENT]]);

        $result = $tool->execute(
            ['target_agent_id' => HANDOVER_TARGET_AGENT, 'prompt' => 'ctx'],
            HANDOVER_AGENT_ID,
            HANDOVER_USER_ID,
            HANDOVER_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });
});
