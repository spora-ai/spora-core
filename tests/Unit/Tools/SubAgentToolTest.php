<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;

const SUB_AGENT_AGENT_ID = 1;
const SUB_AGENT_USER_ID  = 42;
const SUB_AGENT_TASK_ID  = 100;
const SUB_AGENT_TARGET_AGENT = 5;
const SUB_AGENT_NEW_TASK_ID  = 999;
const SUB_AGENT_SUB_CHILD_ID  = 777;

/**
 * @return array{0: SubAgentTool, 1: HandoverServiceInterface&MockInterface, 2: SubAgentServiceInterface&MockInterface, 3: ToolConfigServiceInterface&MockInterface}
 */
function makeSubAgentTool(): array
{
    $handover = Mockery::mock(HandoverServiceInterface::class);
    $subAgent = Mockery::mock(SubAgentServiceInterface::class);
    $config   = Mockery::mock(ToolConfigServiceInterface::class);

    return [new SubAgentTool($handover, $subAgent, $config), $handover, $subAgent, $config];
}

/**
 * Seed real `agents` rows for SUB_AGENT_AGENT_ID and SUB_AGENT_TARGET_AGENT
 * under a shared user-principal. `SubAgentTool::isTargetAllowed()` now
 * cross-checks `principal_id` after the allowlist hit, so any test that
 * exercises the tool past the allowlist needs both agents to exist with
 * the same principal in the (in-memory) DB.
 */
function seedSubAgentAgents(int $userId = SUB_AGENT_USER_ID): int
{
    $principalId = createUserPrincipalPublic($userId);
    $now = date('Y-m-d H:i:s');
    foreach ([SUB_AGENT_AGENT_ID, SUB_AGENT_TARGET_AGENT] as $i => $agentId) {
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => $agentId],
            [
                'principal_id' => $principalId,
                'name'         => $i === 0 ? 'SubAgent Source Agent' : 'SubAgent Target Agent',
                'max_steps'    => $i === 0 ? 10 : 7,
                'is_active'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        );
    }

    return $principalId;
}

describe('SubAgentTool::execute (handover op)', function (): void {

    test('returns failure when target_agent_id is missing', function (): void {
        [$tool] = makeSubAgentTool();

        $result = $tool->execute([], SUB_AGENT_AGENT_ID, SUB_AGENT_USER_ID, SUB_AGENT_TASK_ID);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
    });

    test('returns failure when prompt is missing', function (): void {
        [$tool] = makeSubAgentTool();

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('prompt is required.');
    });

    test('returns failure when userId is null', function (): void {
        [$tool] = makeSubAgentTool();

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            null,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('returns failure when taskId is null', function (): void {
        [$tool] = makeSubAgentTool();

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('current task context');
    });

    test('returns failure when target is not in the allowlist', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [2, 3]]);

        $result = $tool->execute(
            ['target_agent_id' => 1, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('returns failure when service throws InvalidArgumentException', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $handover->allows('handover')
            ->andThrow(new InvalidArgumentException('Source task not found.'));

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('Source task not found.');
    });

    test('returns success with new_task_id and target_agent_id on happy path', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $newTask = new Task();
        $newTask->id = SUB_AGENT_NEW_TASK_ID;
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $handover->allows('handover')
            ->with(SUB_AGENT_TASK_ID, SUB_AGENT_TARGET_AGENT, 'ctx', SUB_AGENT_USER_ID)
            ->andReturn($newTask);

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['op'])->toBe('handover')
            ->and($result->data['handover'])->toBeTrue()
            ->and($result->data['new_task_id'])->toBe(SUB_AGENT_NEW_TASK_ID)
            ->and($result->data['target_agent_id'])->toBe(SUB_AGENT_TARGET_AGENT)
            // The content is rendered as markdown in the chat UI, so the
            // "New task #N" reference is a clickable link to the new task.
            ->and($result->content)->toContain("Task delegated to agent #" . SUB_AGENT_TARGET_AGENT)
            ->and($result->content)->toContain("[New task #" . SUB_AGENT_NEW_TASK_ID . "](/tasks/" . SUB_AGENT_NEW_TASK_ID . ")");
    });
});

describe('SubAgentTool::execute (sub_agent op)', function (): void {

    test('routes to SubAgentService with the right args', function (): void {
        [$tool, , $subAgent, $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);

        $child = new Task();
        $child->id = SUB_AGENT_SUB_CHILD_ID;
        $subAgent->allows('spawn')
            ->with(SUB_AGENT_TASK_ID, SUB_AGENT_TARGET_AGENT, 'do the thing', SUB_AGENT_USER_ID)
            ->andReturn($child);

        $result = $tool->execute(
            ['op' => 'sub_agent', 'target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'do the thing'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['op'])->toBe('sub_agent')
            ->and($result->data['spawned_sub_task_ids'])->toBe([SUB_AGENT_SUB_CHILD_ID])
            ->and($result->data['target_agent_id'])->toBe(SUB_AGENT_TARGET_AGENT)
            ->and($result->content)->toContain('Sub-agent task #' . SUB_AGENT_SUB_CHILD_ID);
    });

    test('returns failure when target_agent_id is missing', function (): void {
        [$tool, , $subAgent] = makeSubAgentTool();

        $result = $tool->execute(
            ['op' => 'sub_agent', 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when prompt is missing', function (): void {
        [$tool, , $subAgent] = makeSubAgentTool();

        $result = $tool->execute(
            ['op' => 'sub_agent', 'target_agent_id' => SUB_AGENT_TARGET_AGENT],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('prompt is required.');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when target is not in the allowlist', function (): void {
        [$tool, , $subAgent, $config] = makeSubAgentTool();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [2, 3]]);

        $result = $tool->execute(
            ['op' => 'sub_agent', 'target_agent_id' => 1, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $subAgent->shouldNotHaveReceived('spawn');
    });

    test('returns failure when SubAgentService throws InvalidArgumentException', function (): void {
        [$tool, , $subAgent, $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $subAgent->allows('spawn')
            ->andThrow(new InvalidArgumentException('Parent task not found.'));

        $result = $tool->execute(
            ['op' => 'sub_agent', 'target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('Parent task not found.');
    });
});

describe('SubAgentTool::describeAction', function (): void {

    test('renders the target agent id for the handover op', function (): void {
        [$tool] = makeSubAgentTool();

        expect($tool->describeAction(['target_agent_id' => SUB_AGENT_TARGET_AGENT]))
            ->toBe('Hand over the task to agent #' . SUB_AGENT_TARGET_AGENT . '.');
    });

    test('renders the agent id for the sub_agent op', function (): void {
        [$tool] = makeSubAgentTool();

        expect($tool->describeAction(['op' => 'sub_agent', 'target_agent_id' => SUB_AGENT_TARGET_AGENT]))
            ->toBe('Spawn a sub-agent on agent #' . SUB_AGENT_TARGET_AGENT . ' and wait for its result.');
    });
});

describe('SubAgentTool::getParametersSchema', function (): void {

    test('requires the op discriminator and pin-points per-op required params', function (): void {
        [$tool] = makeSubAgentTool();

        $schema = $tool->getParametersSchema();

        expect($schema['required'])->toContain('op')
            ->and($schema['required'])->toContain('prompt')
            ->and($schema['properties']['op']['enum'])->toBe(['handover', 'sub_agent'])
            ->and($schema['properties']['target_agent_id'])->toBeArray()
            ->and($schema['properties']['prompt'])->toBeArray();
    });

    test('target_agent_id declares enumSource pointing at allowed_target_agents', function (): void {
        // The static schema (used by runtime validation) carries no enum
        // and no description suffix — the LLM-side enrichment only kicks
        // in via getLlmParametersSchema() with the runtime maps threaded.
        [$tool] = makeSubAgentTool();

        $schema = $tool->getParametersSchema();

        expect($schema['properties']['target_agent_id'])->not->toHaveKey('enum')
            ->and($schema['properties']['target_agent_id']['description'])
                ->not->toContain('Allowed values:');
    });

    test('getLlmParametersSchema populates enum from the resolved labels so the LLM picks by name', function (): void {
        [$tool] = makeSubAgentTool();

        $schema = $tool->getLlmParametersSchema(
            ['allowed_target_agents' => [11, 4]],
            ['allowed_target_agents' => ['Legal Agent (#11)', 'Sales Agent (#4)']],
        );

        // The single target_agent_id param is what the LLM fills for both
        // ops — the same enum + suffix drives both. No more separate
        // agent_id param next to target_agent_id. The enum is now the
        // "Name (#id)" labels, not raw ids, so the model can refer to
        // agents by name in its reasoning; the tool parses the label
        // back to an int at execute time.
        expect($schema['properties']['target_agent_id']['enum'])->toBe(['Legal Agent (#11)', 'Sales Agent (#4)'])
            ->and($schema['properties']['target_agent_id']['type'])->toBe('string')
            ->and($schema['properties']['target_agent_id']['description'])
                ->toContain('Legal Agent (#11)')
                ->toContain('Sales Agent (#4)');
    });

    test('getLlmParametersSchema with an empty allowlist emits no enum and no suffix (safe-by-default)', function (): void {
        [$tool] = makeSubAgentTool();

        $schema = $tool->getLlmParametersSchema([], []);

        // Empty list -> no enum (would be invalid JSON Schema) and no
        // suffix ("Allowed values: " would mislead the model into
        // thinking there is something to pick). Static description stands.
        expect($schema['properties']['target_agent_id'])->not->toHaveKey('enum')
            ->and($schema['properties']['target_agent_id']['description'])
                ->not->toContain('Allowed values:');
    });
});

describe('SubAgentTool back-compat: single-op agents may omit `op`', function (): void {

    test('OperationSchemaFilter::filter strips `op` from required[] when only handover is allowed', function (): void {
        [$tool] = makeSubAgentTool();
        $schema = $tool->getParametersSchema();

        $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['handover'], 'op');

        expect($filtered['required'])->not->toContain('op')
            ->and($filtered['properties']['op']['enum'])->toBe(['handover']);
    });

    test('OperationSchemaFilter::filter keeps `op` in required[] when both ops are allowed', function (): void {
        [$tool] = makeSubAgentTool();
        $schema = $tool->getParametersSchema();

        $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['handover', 'sub_agent'], 'op');

        expect($filtered['required'])->toContain('op');
    });

    test('SchemaValidator accepts a handover call without `op` when the op is unambiguous', function (): void {
        [$tool] = makeSubAgentTool();
        $schema = $tool->getParametersSchema();

        // Mirrors the runtime call site: SchemaValidator::validate($args, $schema, $operationName).
        // HasOperations::getOperationName() resolves the missing op to the first declared op.
        Spora\Agents\SchemaValidator::validate(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'x'],
            $schema,
            'handover',
        );

        expect(true)->toBeTrue(); // no exception was thrown
    });

    test('SchemaValidator rejects a sub_agent call with no target_agent_id', function (): void {
        [$tool] = makeSubAgentTool();
        $schema = $tool->getParametersSchema();

        // sub_agent requires `target_agent_id`; supplying neither id is a
        // wrong-param-for-op case the validator must catch.
        expect(fn() => Spora\Agents\SchemaValidator::validate(
            ['prompt' => 'x'],
            $schema,
            'sub_agent',
        ))->toThrow(InvalidArgumentException::class, "Required argument 'target_agent_id'");
    });
});

describe('SubAgentTool::execute (target_agent_id accepts label, #id, or int)', function (): void {

    test('parses the resolved "Name (#id)" label back to the int id', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $handover->allows('handover')
            ->with(SUB_AGENT_TASK_ID, SUB_AGENT_TARGET_AGENT, 'ctx', SUB_AGENT_USER_ID)
            ->andReturn(new Task(['id' => SUB_AGENT_NEW_TASK_ID]));

        // The schema's enum is the resolved label "SubAgent Target Agent (#5)";
        // that's the wire format an LLM would send.
        $result = $tool->execute(
            ['target_agent_id' => 'SubAgent Target Agent (#' . SUB_AGENT_TARGET_AGENT . ')', 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['target_agent_id'])->toBe(SUB_AGENT_TARGET_AGENT);
    });

    test('parses the unresolved "#id" placeholder back to the int id', function (): void {
        // Foreign ids in a stale global row degrade to "#id" labels. The
        // tool still has to extract the id so the allowlist check works.
        [$tool, $handover, , $config] = makeSubAgentTool();
        seedSubAgentAgents();
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $handover->allows('handover')
            ->with(SUB_AGENT_TASK_ID, SUB_AGENT_TARGET_AGENT, 'ctx', SUB_AGENT_USER_ID)
            ->andReturn(new Task(['id' => SUB_AGENT_NEW_TASK_ID]));

        $result = $tool->execute(
            ['target_agent_id' => '#' . SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['target_agent_id'])->toBe(SUB_AGENT_TARGET_AGENT);
    });

    test('rejects a malformed label as missing', function (): void {
        [$tool, $handover] = makeSubAgentTool();

        $result = $tool->execute(
            ['target_agent_id' => 'not a label', 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
        $handover->shouldNotHaveReceived('handover');
    });

    test('rejects an empty-string label as missing', function (): void {
        [$tool, $handover] = makeSubAgentTool();

        $result = $tool->execute(
            ['target_agent_id' => '', 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toBe('target_agent_id is required.');
        $handover->shouldNotHaveReceived('handover');
    });
});

describe('SubAgentTool::isTargetAllowed (intra-principal defense in depth)', function (): void {

    test('returns false when source and target belong to different principals even though the target id is in the stored allowlist', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();

        // Source owned by user A, target owned by user B — different principals.
        $principalA = createUserPrincipalPublic(SUB_AGENT_USER_ID);
        $otherUserId = SUB_AGENT_USER_ID + 100;
        $principalB = createUserPrincipalPublic($otherUserId);

        $now = date('Y-m-d H:i:s');
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => SUB_AGENT_AGENT_ID],
            ['principal_id' => $principalA, 'name' => 'Source A', 'max_steps' => 10, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => SUB_AGENT_TARGET_AGENT],
            ['principal_id' => $principalB, 'name' => 'Target B', 'max_steps' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        // Stored allowlist contains the foreign id — tampered payload / copy-paste.
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('returns false when the source agent cannot be loaded (fail-closed)', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();
        // Only the target is seeded; the source agent does NOT exist in the DB.
        $principalId = createUserPrincipalPublic(SUB_AGENT_USER_ID);
        $now = date('Y-m-d H:i:s');
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => SUB_AGENT_TARGET_AGENT],
            ['principal_id' => $principalId, 'name' => 'Target Only', 'max_steps' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });

    test('succeeds when source and target share a group-principal', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();

        $groupPrincipalId = createGroupPrincipalPublicForSubAgent();
        $now = date('Y-m-d H:i:s');
        foreach ([SUB_AGENT_AGENT_ID, SUB_AGENT_TARGET_AGENT] as $i => $agentId) {
            Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
                ['id' => $agentId],
                [
                    'principal_id' => $groupPrincipalId,
                    'name'         => $i === 0 ? 'Group Source' : 'Group Target',
                    'max_steps'    => $i === 0 ? 10 : 7,
                    'is_active'    => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
            );
        }

        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);
        $handover->shouldReceive('handover')->once()->andReturn(
            new Task(['status' => 'RUNNING']),
        );

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeTrue();
    });

    test('rejects a user-principal target when the source is on a group-principal', function (): void {
        [$tool, $handover, , $config] = makeSubAgentTool();

        $groupPrincipalId   = createGroupPrincipalPublicForSubAgent();
        $userPrincipalId    = createUserPrincipalPublic(SUB_AGENT_USER_ID);
        $now = date('Y-m-d H:i:s');
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => SUB_AGENT_AGENT_ID],
            ['principal_id' => $groupPrincipalId, 'name' => 'Group Source', 'max_steps' => 10, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );
        Illuminate\Database\Capsule\Manager::table('agents')->updateOrInsert(
            ['id' => SUB_AGENT_TARGET_AGENT],
            ['principal_id' => $userPrincipalId, 'name' => 'User Target', 'max_steps' => 7, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        );

        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [SUB_AGENT_TARGET_AGENT]]);

        $result = $tool->execute(
            ['target_agent_id' => SUB_AGENT_TARGET_AGENT, 'prompt' => 'ctx'],
            SUB_AGENT_AGENT_ID,
            SUB_AGENT_USER_ID,
            SUB_AGENT_TASK_ID,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_target_agents list');
        $handover->shouldNotHaveReceived('handover');
    });
});

/**
 * Inline helper for the group-principal scenarios in
 * {@see describe('SubAgentTool::isTargetAllowed (intra-principal defense in depth)')}.
 * Lives in this file (not the shared CrossFileTestHelpers) because no
 * other test currently needs a group principal; promoting it to the
 * shared helper would widen the test surface for one consumer.
 *
 * Calls {@see createUserPrincipalPublic()} first so the `groups.created_by_user_id`
 * FK points at a real `users` row — the user-principal helper inserts a
 * stub user with id `SUB_AGENT_USER_ID` when one isn't already there.
 */
function createGroupPrincipalPublicForSubAgent(): int
{
    createUserPrincipalPublic(SUB_AGENT_USER_ID);

    $existing = Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', 'group')->where('group_id', 9001)->value('id');
    if ($existing !== null) {
        return (int) $existing;
    }

    Illuminate\Database\Capsule\Manager::table('groups')->updateOrInsert(
        ['id' => 9001],
        [
            'name'                => 'SubAgent Test Group',
            'created_by_user_id'  => SUB_AGENT_USER_ID,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ],
    );

    return (int) Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
        'type'       => 'group',
        'group_id'   => 9001,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
