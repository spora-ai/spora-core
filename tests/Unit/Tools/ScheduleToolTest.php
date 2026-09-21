<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\PromptTemplateService;
use Spora\Services\ScheduledRunService;
use Spora\Tools\ScheduleTool;

const SCHEDULE_TOOL_TZ        = 'Europe/Berlin';
const SCHEDULE_TOOL_CRON      = '0 7 * * *';
const SCHEDULE_TOOL_RUN_AT    = '+2 hours';
const SCHEDULE_TOOL_TEMPLATE_NAME = 'Daily summary';

function makeScheduleToolTestFixture(): array
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps) {
        $agent = Agent::find($agentId);
        $principalId = (int) ($agent === null ? 0 : $agent->principal_id);
        return Spora\Models\Task::create([
            'agent_id'       => $agentId,
            'principal_id'   => $principalId,
            'trigger_user_id' => 1,
            'status'         => 'RUNNING',
            'user_prompt'    => $prompt,
            'max_steps'      => $maxSteps,
            'step_count'     => 0,
        ]);
    });
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $scheduledRunService = new ScheduledRunService($orchestrator, $mercure);
    $promptTemplateService = new PromptTemplateService();

    $principalResolver = new PrincipalResolver();
    $principalService = new PrincipalService($principalResolver);

    $tool = new ScheduleTool(
        $scheduledRunService,
        $promptTemplateService,
        new ScheduleTool\ScheduleToolCollaborators(
            principalResolver: $principalResolver,
            principalService: $principalService,
        ),
        $principalResolver,
        $principalService,
    );

    return [$tool, $scheduledRunService, $promptTemplateService];
}

function makeScheduleToolOwner(): array
{
    $auth = bootAuthLayer();
    $userId = $auth->register(
        'scheduletool-' . bin2hex(random_bytes(4)) . '@example.com',
        'Password1!',
        'Schedule Tool Owner',
    );

    $principalId = createUserPrincipalPublic($userId);

    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'ScheduleToolAgent',
        'max_steps'    => 10,
        'allow_followup' => true,
        'is_active'    => true,
    ]);

    return [$userId, (int) $agent->id, $principalId];
}

function seedSchedule(int $agentId, int $userId, array $overrides = []): ScheduledRun
{
    return ScheduledRun::create(array_merge([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'template_id'     => null,
        'raw_prompt'      => 'Hello {{date}}',
        'cron_expression' => SCHEDULE_TOOL_CRON,
        'timezone'        => 'UTC',
        'is_active'       => true,
    ], $overrides));
}

function seedTemplate(int $agentId, array $overrides = []): AgentPromptTemplate
{
    return AgentPromptTemplate::create(array_merge([
        'agent_id'        => $agentId,
        'name'            => SCHEDULE_TOOL_TEMPLATE_NAME,
        'prompt_template' => 'Summarize {{date}}',
        'variables'       => json_encode([['key' => 'date', 'default_value' => 'today']]),
        'is_active'       => true,
    ], $overrides));
}

describe('ScheduleTool::list_schedules', function (): void {
    test('returns empty list when no schedules exist', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute(
            ['action' => 'list_schedules'],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('No schedules')
            ->and($result->data['schedules'])->toBe([]);
    });

    test('returns slim schedules with summary, is_active, next_run_at', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $a = seedSchedule($agentId, $userId, ['raw_prompt' => 'Morning brief', 'cron_expression' => SCHEDULE_TOOL_CRON]);
        $b = seedSchedule($agentId, $userId, ['raw_prompt' => 'Run once', 'cron_expression' => null, 'run_at' => date('Y-m-d H:i:s', strtotime('+2 hours'))]);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'list_schedules'],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue();
        $rows = $result->data['schedules'];
        expect($rows)->toHaveCount(2);
        $ids = array_column($rows, 'schedule_id');
        expect($ids)->toContain($a->id)->toContain($b->id);
        foreach ($rows as $row) {
            expect($row)->toHaveKeys(['schedule_id', 'summary', 'is_active', 'next_run_at']);
        }
    });

    test('refuses to list for a hidden cross-user agent', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $strangerAgentId = (int) Agent::create([
            'principal_id' => createUserPrincipalPublic(99_999_999),
            'name'         => 'Stranger',
            'max_steps'    => 5,
            'is_active'    => true,
        ])->id;

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'list_schedules', 'agent_id' => $strangerAgentId],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('agent not found');
    });
});

describe('ScheduleTool::list_prompt_templates', function (): void {
    test('returns empty list when no templates exist', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute(
            ['action' => 'list_prompt_templates'],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('No prompt templates')
            ->and($result->data['prompt_templates'])->toBe([]);
    });

    test('returns slim templates with id, name, description, max_steps, is_active', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId, ['description' => 'Greeting', 'max_steps' => 12]);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'list_prompt_templates'],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue();
        $rows = $result->data['prompt_templates'];
        expect($rows)->toHaveCount(1);
        expect($rows[0])->toMatchArray([
            'template_id' => $tpl->id,
            'name'        => SCHEDULE_TOOL_TEMPLATE_NAME,
            'description' => 'Greeting',
            'max_steps'   => 12,
            'is_active'   => true,
        ]);
    });
});

describe('ScheduleTool::read_schedule', function (): void {
    test('returns the full schedule resource', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'read_schedule', 'schedule_id' => $run->id],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['id'])->toBe($run->id)
            ->and($result->content)->toContain("Schedule #{$run->id}");
    });

    test('rejects non-positive schedule_id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        foreach ([0, 'abc', -1] as $bad) {
            $result = $tool->execute(
                ['action' => 'read_schedule', 'schedule_id' => $bad],
                $agentId,
                $userId,
            );
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('schedule_id')
                ->and($result->content)->toContain('positive integer');
        }
    });

    test('returns "not found" for a cross-user schedule_id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        seedSchedule($agentId, $userId, ['raw_prompt' => 'mine']);

        // Stranger tries to read a non-existent id on a stranger's agent —
        // we hit the visibility gate first.
        $strangerAuth = bootAuthLayer();
        $strangerId = $strangerAuth->register(
            'snoop-' . bin2hex(random_bytes(4)) . '@example.com',
            'Password1!',
            'Snoop',
        );

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'read_schedule', 'schedule_id' => 999_999_999],
            $agentId,
            $strangerId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('schedule not found');
    });
});

describe('ScheduleTool::read_prompt_template', function (): void {
    test('returns the full template resource', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'read_prompt_template', 'template_id' => $tpl->id],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['template']['id'])->toBe($tpl->id);
    });

    test('returns "not found" for an unknown template_id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute(
            ['action' => 'read_prompt_template', 'template_id' => 999_999_999],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('prompt template not found');
    });
});

describe('ScheduleTool::create_schedule', function (): void {
    test('happy path with cron_expression + raw_prompt persists the row', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'Daily standup prompt',
                'timezone'        => SCHEDULE_TOOL_TZ,
                'max_steps_override' => 7,
                'is_active'       => true,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Created schedule')
            ->and($result->data['scheduled_run']['agent_id'])->toBe($agentId)
            ->and($result->data['scheduled_run']['cron_expression'])->toBe(SCHEDULE_TOOL_CRON)
            ->and($result->data['scheduled_run']['timezone'])->toBe(SCHEDULE_TOOL_TZ)
            ->and($result->data['scheduled_run']['max_steps_override'])->toBe(7);
    });

    test('happy path with template_id instead of raw_prompt', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId, ['name' => 'Brief']);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'template_id'     => $tpl->id,
                'timezone'        => 'UTC',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['template_id'])->toBe($tpl->id);
    });

    test('rejects when both cron_expression and run_at are provided', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'run_at'          => '2026-12-31T23:59:00+00:00',
                'raw_prompt'      => 'ambiguous',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('mutually exclusive');
    });

    test('rejects when neither cron_expression nor run_at is provided', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'raw_prompt' => 'what schedule?',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('cron_expression')
            ->and($result->content)->toContain('run_at');
    });

    test('rejects when neither template_id nor raw_prompt is provided', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('template_id')
            ->and($result->content)->toContain('raw_prompt');
    });

    test('rejects an invalid cron_expression', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => 'not-a-cron',
                'raw_prompt'      => 'never',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('cron_expression')
            ->and($result->content)->toContain('invalid');
    });

    test('rejects an unknown IANA timezone', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'tz',
                'timezone'        => 'Mars/Olympus',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('timezone')
            ->and($result->content)->toContain('IANA');
    });

    test('rejects out-of-range max_steps_override', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression'    => SCHEDULE_TOOL_CRON,
                'raw_prompt'         => 'me',
                'max_steps_override' => 999,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('max_steps_override');
    });

    test('rejects unknown keys (strict allowlist)', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'          => 'create_schedule',
            'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'me',
                'sql_injection'   => '; DROP TABLE agents;--',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not a known slim-payload key');
    });
});

describe('ScheduleTool::create_prompt_template', function (): void {
    test('happy path persists the template', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'           => 'create_prompt_template',
            'template_payload' => [
                'name'            => 'Greet {{user_name}}',
                'description'     => 'Daily greeting',
                'prompt_template' => 'Hi {{user_name}}, today is {{date}}.',
                'variables'       => [
                    ['key' => 'user_name', 'default_value' => 'friend'],
                    ['key' => 'date', 'default_value' => 'today'],
                ],
                'max_steps'       => 5,
                'is_active'       => true,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['template']['name'])->toBe('Greet {{user_name}}')
            ->and($result->data['template']['agent_id'])->toBe($agentId)
            ->and($result->data['template']['max_steps'])->toBe(5);
    });

    test('rejects empty name', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'           => 'create_prompt_template',
            'template_payload' => [
                'name'            => '   ',
                'prompt_template' => 'no name',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`name`');
    });

    test('rejects missing prompt_template', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'           => 'create_prompt_template',
            'template_payload' => [
                'name' => 'Empty prompt',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('prompt_template');
    });

    test('rejects out-of-range max_steps', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'           => 'create_prompt_template',
            'template_payload' => [
                'name'            => 'Big steps',
                'prompt_template' => 'go',
                'max_steps'       => 200,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('max_steps');
    });

    test('rejects malformed variables', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'           => 'create_prompt_template',
            'template_payload' => [
                'name'            => 'Bad vars',
                'prompt_template' => '...',
                'variables'       => [['default_value' => 'no key']],
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('variables');
    });
});

describe('ScheduleTool::update_schedule', function (): void {
    test('happy path partial patch (is_active toggle)', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $run->id,
            'schedule_patch' => ['is_active' => false],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['is_active'])->toBeFalse();
    });

    test('rejects unknown patch keys', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $run->id,
            'schedule_patch' => ['sql_injection' => 'x'],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not a mutable key');
    });

    test('rejects simultaneous cron_expression and run_at', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $run->id,
            'schedule_patch' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'run_at'          => '2026-12-31T23:59:00+00:00',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('mutually exclusive');
    });

    test('returns "not found" for an unknown schedule', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => 999_999_999,
            'schedule_patch' => ['is_active' => false],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('schedule not found');
    });
});

describe('ScheduleTool::update_prompt_template', function (): void {
    test('happy path renames the template', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_prompt_template',
            'template_id'    => $tpl->id,
            'template_patch' => ['name' => 'Renamed'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['template']['name'])->toBe('Renamed');
    });

    test('rejects empty-patch', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_prompt_template',
            'template_id'    => $tpl->id,
            'template_patch' => [],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('patch object is required');
    });
});

describe('ScheduleTool::delete_schedule + delete_prompt_template', function (): void {
    test('delete_schedule returns success and removes the row', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'      => 'delete_schedule',
            'schedule_id' => $run->id,
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['deleted'])->toBeTrue()
            ->and(ScheduledRun::find($run->id))->toBeNull();
    });

    test('delete_schedule returns "not found" for an unknown id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'      => 'delete_schedule',
            'schedule_id' => 999_999_999,
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('schedule not found');
    });

    test('delete_prompt_template returns success and removes the row', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $tpl = seedTemplate($agentId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'      => 'delete_prompt_template',
            'template_id' => $tpl->id,
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and(AgentPromptTemplate::find($tpl->id))->toBeNull();
    });
});

describe('ScheduleTool::trigger_schedule', function (): void {
    test('happy path returns a task_id and deactivates one-shot', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId, [
            'cron_expression' => null,
            'run_at'          => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ]);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'      => 'trigger_schedule',
            'schedule_id' => $run->id,
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['task_id'])->toBeInt()
            ->and($result->data['scheduled_run']['is_active'])->toBeFalse();
    });

    test('surface PromptTemplateMissingException as a friendly error', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        // Insert via Eloquent (template_id = null passes FK), then patch the
        // row directly to point at a non-existent template id — that simulates
        // a deleted template without tripping the FK cascade. Pest wraps every
        // test in a transaction; use SQLite's per-transaction
        // `defer_foreign_keys = ON` so the update can violate the FK only
        // within this test's transactional scope.
        $run = seedSchedule($agentId, $userId, [
            'template_id'     => null,
            'raw_prompt'      => 'fallback',
            'cron_expression' => SCHEDULE_TOOL_CRON,
        ]);

        $missingTemplateId = 999_999_999;
        Capsule::statement('PRAGMA defer_foreign_keys = ON');
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update(['template_id' => $missingTemplateId]);

        $run->refresh();
        expect($run->template_id)->toBe($missingTemplateId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'      => 'trigger_schedule',
            'schedule_id' => $run->id,
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('trigger_schedule')
            ->and($result->content)->toContain('template');
    });
});

describe('ScheduleTool::describeAction', function (): void {
    test('summarises each operation without leaking prompt bodies or raw_prompt content', function (): void {
        [$tool] = makeScheduleToolTestFixture();

        $cases = [
            // create_schedule echo includes cron + agent_id but NOT raw_prompt body
            ['action' => 'create_schedule', 'agent_id' => 7, 'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'SECRET-RAW-PROMPT-BODY',
                'template_id'     => null,
            ]],
            // create_prompt_template echo includes the name (it's a label the
            // operator must see to identify the template) but NOT the
            // prompt_template body
            ['action' => 'create_prompt_template', 'agent_id' => 7, 'template_payload' => [
                'name'            => 'Public name',
                'prompt_template' => 'SECRET-PROMPT-BODY',
            ]],
            // trigger_schedule shows schedule_id + agent_id only, no prompt body
            ['action' => 'trigger_schedule', 'schedule_id' => 42, 'agent_id' => 7],
        ];

        foreach ($cases as $arguments) {
            $label = $tool->describeAction($arguments);
            expect($label)->toBeString()
                ->and($label)->not->toContain('SECRET-RAW-PROMPT-BODY')
                ->and($label)->not->toContain('SECRET-PROMPT-BODY');
        }
    });
});

describe('ScheduleTool — schema', function (): void {
    test('auto-generated parameters schema advertises every operation', function (): void {
        [$tool] = makeScheduleToolTestFixture();
        $schema = $tool->getParametersSchema();

        expect($schema['type'] ?? null)->toBe('object');
        $enum = $schema['properties']['action']['enum'] ?? null;
        expect($enum)->toBeArray();

        $expected = [
            'list_schedules',
            'list_prompt_templates',
            'read_schedule',
            'read_prompt_template',
            'create_schedule',
            'create_prompt_template',
            'update_schedule',
            'update_prompt_template',
            'delete_schedule',
            'delete_prompt_template',
            'trigger_schedule',
        ];
        foreach ($expected as $name) {
            expect($enum)->toContain($name);
        }

        // `action` must be in `required` so the LLM picks one.
        expect($schema['required'] ?? [])->toContain('action');
    });
});

describe('ScheduleTool — per-op defaults', function (): void {
    test('list_* + read_* default to enabled/no-approval; everything else approval-required', function (): void {
        [$tool] = makeScheduleToolTestFixture();

        $open = ['list_schedules', 'list_prompt_templates', 'read_schedule', 'read_prompt_template'];
        foreach ($open as $op) {
            expect($tool->isEnabledByDefault($op))->toBeTrue("{$op} should be enabled by default")
                ->and($tool->requiresApprovalByDefault($op))->toBeFalse("{$op} should not require approval");
        }

        $gated = [
            'create_schedule', 'create_prompt_template',
            'update_schedule', 'update_prompt_template',
            'delete_schedule', 'delete_prompt_template',
            'trigger_schedule',
        ];
        foreach ($gated as $op) {
            expect($tool->isEnabledByDefault($op))->toBeFalse("{$op} should be disabled by default")
                ->and($tool->requiresApprovalByDefault($op))->toBeTrue("{$op} should require approval");
        }
    });
});
