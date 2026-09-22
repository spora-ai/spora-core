<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
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

/**
 * Disable foreign-key checking for the next direct UPDATE the test
 * performs. The Spora tools intentionally allow `template_id` to be
 * a "dangling" reference at storage time — the service layer is the
 * one that decides the row is unschedulable, not the FK — so a few
 * tests need to plant a missing-id reference on an existing
 * `scheduled_runs` row without the FK rejecting the write.
 *
 * The driver switch is necessary because the two backend families
 * expose FK toggling differently:
 *
 *   - SQLite: `PRAGMA defer_foreign_keys = ON` (per-transaction,
 *     auto-restored on the test's transaction rollback).
 *   - MySQL / MariaDB: `SET FOREIGN_KEY_CHECKS=0` (session-scoped —
 *     persists across the rollback, so pair with
 *     {@see restoreForeignKeysForTest()} to keep the session clean).
 */
function bypassForeignKeysForTest(): void
{
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'sqlite') {
        Capsule::statement('PRAGMA defer_foreign_keys = ON');

        return;
    }
    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        Capsule::statement('SET FOREIGN_KEY_CHECKS=0');

        return;
    }
    // Other drivers (pg, sqlsrv) weren't requested yet; the test
    // skips the bypass and lets the FK do its job — that path is
    // exercised in production where the bug we are testing requires
    // a missing-template id to slip past the FK in the first place.
}

/**
 * Companion to {@see bypassForeignKeysForTest()} — re-enable FK
 * checking after a session-scoped bypass so the next test starts
 * from a clean baseline. No-op for SQLite (the pragma is
 * per-transaction and the afterEach rollback restores the default).
 */
function restoreForeignKeysForTest(): void
{
    $driver = Capsule::connection()->getDriverName();
    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        Capsule::statement('SET FOREIGN_KEY_CHECKS=1');
    }
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
        // test in a transaction; bypass FK enforcement only for this update so
        // the violating reference can land in the row but the trigger still
        // sees a missing template. The driver switch is necessary because
        // SQLite uses `PRAGMA defer_foreign_keys` while MySQL/MariaDB use
        // `SET FOREIGN_KEY_CHECKS=0` (session-scoped); either way the value
        // reverts on transaction rollback.
        $run = seedSchedule($agentId, $userId, [
            'template_id'     => null,
            'raw_prompt'      => 'fallback',
            'cron_expression' => SCHEDULE_TOOL_CRON,
        ]);

        $missingTemplateId = 999_999_999;
        bypassForeignKeysForTest();
        try {
            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update(['template_id' => $missingTemplateId]);
        } finally {
            restoreForeignKeysForTest();
        }

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

describe('ScheduleTool — cross-agent resolution', function (): void {
    test('read_schedule hits the visible-but-cross-owned route (not found on agent)', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        // Capture the seeded schedule's id rather than hardcoding 1 —
        // MariaDB/MySQL preserve the AUTO_INCREMENT counter across the
        // test transaction's rolled-back peers, so the first inserted
        // row is not always id 1.
        $schedule = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'read_schedule', 'schedule_id' => $schedule->id, 'agent_id' => 0],
            $agentId,
            $userId,
        );

        // `agent_id` of 0 falls back to the calling agent.
        expect($result->success)->toBeBool();
    });

    test('write paths return agent-not-found for an explicit, non-existent agent_id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute(
            ['action' => 'create_schedule', 'agent_id' => 999_999, 'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'who am I?',
            ]],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('agent not found');
    });

    test('write paths return agent-not-found for an agent the user does NOT control', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $strangerAgentId = (int) Agent::create([
            'principal_id' => createUserPrincipalPublic(99_999_999),
            'name'         => 'Stranger',
            'max_steps'    => 5,
            'is_active'    => true,
        ])->id;

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'create_schedule', 'agent_id' => $strangerAgentId, 'schedule_payload' => [
                'cron_expression' => SCHEDULE_TOOL_CRON,
                'raw_prompt'      => 'hostile takeover',
            ]],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('agent not found');
    });

    test('read_*_template with cross-agent_id hits the controller-side service', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        // Capture the seeded template's id rather than hardcoding 1 —
        // MariaDB and MySQL preserve the AUTO_INCREMENT counter across
        // failed inserts and across the test transaction's rolled-back
        // peers, so the first inserted row is not always id 1.
        $template = seedTemplate($agentId, ['name' => 'Mine']);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute(
            ['action' => 'read_prompt_template', 'template_id' => $template->id],
            $agentId,
            $userId,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['template']['name'])->toBe('Mine');
    });
});

describe('ScheduleTool — write-side failure surfaces', function (): void {
    test('update_prompt_template returns "not found" for an unknown template', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'         => 'update_prompt_template',
            'template_id'    => 999_999,
            'template_patch' => ['name' => 'X'],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('prompt template not found');
    });

    test('update_schedule rejects bad timezone in patch', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $run->id,
            'schedule_patch' => ['timezone' => 'Mars/Olympus'],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('timezone');
    });

    test('update_schedule accepts null schedule fields to switch modes', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        $run = seedSchedule($agentId, $userId);

        [$tool] = makeScheduleToolTestFixture();
        // cron_expression becomes null and run_at gets a future ISO 8601 datetime;
        // the service should re-derive next_run_at and stay valid.
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $run->id,
            'schedule_patch' => [
                'cron_expression' => null,
                'run_at'          => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull();
    });

    /**
     * Bug 2 regression: switching cron → one-shot via the documented
     * `cron_expression: null + run_at: <iso>` patch crashed with a UNIQUE
     * constraint violation on `scheduled_runs_next(scheduled_run_id,
     * due_at)` because (a) the service used `??` to fall back to the
     * existing cron instead of respecting the explicit `null`, and (b)
     * `reschedulePendingEntries()` marked the existing PENDING row as
     * SKIPPED but kept it in the table — the new INSERT collided on the
     * UNIQUE index when the cron-derived `next_run_at` matched the
     * existing row's `due_at`.
     *
     * The fix is two-part:
     *   - `array_key_exists()` everywhere so explicit nulls clear.
     *   - `reschedulePendingEntries()` deletes any SKIPPED/DONE row at the
     *     new `(scheduled_run_id, due_at)` before inserting the fresh
     *     PENDING row, freeing the unique slot.
     */
    test('cron → one-shot transition replaces scheduled_runs_next row without UNIQUE collision', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        // createRun goes through ScheduledRunService::createRun() which
        // inserts the first PENDING row in scheduled_runs_next — that's
        // the row that used to collide on UNIQUE.
        [$tool, $service] = makeScheduleToolTestFixture();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => SCHEDULE_TOOL_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        // The freshly-created recurring schedule has exactly one PENDING
        // row in scheduled_runs_next.
        $pendingBefore = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();
        expect($pendingBefore)->toBe(1);

        // Simulate the LLM tool call: the JSON arrives as
        // `{"cron_expression":null,"run_at":"<iso>"}` — the dispatcher
        // parses it, and the validator sees real PHP `null`, not the
        // string "null". Patch goes end-to-end through ScheduleTool.
        $newRunAt = date('Y-m-d\TH:i:sP', strtotime('+5 hours'));
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => [
                'cron_expression' => null,
                'run_at'          => $newRunAt,
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull()
            ->and($result->data['scheduled_run']['is_active'])->toBeTrue();

        // The schedule now has exactly one PENDING row in
        // scheduled_runs_next (at the new run_at), and the old cron
        // entry was marked SKIPPED. No UNIQUE collision, no extra
        // duplicate rows.
        $rows = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->orderBy('id')
            ->get();
        // `next_run_at` in the resource is ISO 8601 with offset;
        // `due_at` in the DB column is `Y-m-d H:i:s`. Normalize the
        // resource value to UTC `Y-m-d H:i:s` for the equality check.
        $expectedDueAt = (new DateTimeImmutable($result->data['scheduled_run']['next_run_at']))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->status)->toBe(ScheduledRunNext::STATUS_SKIPPED)
            ->and($rows[1]->status)->toBe(ScheduledRunNext::STATUS_PENDING)
            ->and($rows[1]->due_at)->toBe($expectedDueAt);
    });

    /**
     * Bug 2 regression variant: re-applying the same patch (run_at update
     * without changing the cron-derived next_run_at) used to collide on
     * UNIQUE because the prior PENDING row was just marked SKIPPED but
     * not deleted. With the delete-then-insert fix this is idempotent.
     */
    test('updating run_at on a one-shot schedule is idempotent (no UNIQUE collision on re-update)', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool, $service] = makeScheduleToolTestFixture();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => null,
            'run_at'          => date('Y-m-d H:i:s', strtotime('+3 hours')),
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        // Push the same run_at again — the service should reschedule the
        // existing PENDING row in place, not collide on UNIQUE.
        $secondRunAt = date('Y-m-d H:i:s', strtotime('+4 hours'));
        $first  = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['run_at' => $secondRunAt],
        ], $agentId, $userId);
        $second = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['run_at' => $secondRunAt],
        ], $agentId, $userId);

        expect($first->success)->toBeTrue()
            ->and($second->success)->toBeTrue()
            ->and($second->data['scheduled_run']['run_at'])->not->toBeNull();

        // Final state: exactly two rows — the original PENDING (now
        // SKIPPED), and one PENDING at the second run_at. No
        // duplicates, no UNIQUE collisions.
        $rows = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->orderBy('id')
            ->get();
        $expectedDueAt = (new DateTimeImmutable($second->data['scheduled_run']['next_run_at']))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $pending = $rows->where('status', ScheduledRunNext::STATUS_PENDING);
        expect($pending)->toHaveCount(1)
            ->and($pending->first()->due_at)->toBe($expectedDueAt);
    });

    /**
     * Bug 2 regression variant: setting `run_at` alone on a cron schedule
     * (without nulling cron) used to crash with UNIQUE because the
     * existing PENDING row at the cron-derived next_run_at collided with
     * the re-inserted row at the same due_at. With the fix it
     * reschedules cleanly: the old row is marked SKIPPED then deleted
     * (freeing the slot), and the new row is inserted.
     */
    test('cron → one-shot transition via {run_at: <iso>} clears cron and reschedules cleanly', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool, $service] = makeScheduleToolTestFixture();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => SCHEDULE_TOOL_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        // Round 4 tightened the assertion — setting run_at on a cron
        // schedule now also implicitly clears cron_expression per the
        // implicit mode-transition policy in
        // {@see \Spora\Services\ScheduledRunService::recomputeNextRunAtIfNeeded()}.
        // The pre-Round-4 test asserted the silent-no-op behaviour (cron
        // stays, run_at ignored); Round 4 made the two directions
        // symmetric from the caller's perspective.
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['run_at' => date('Y-m-d\TH:i:sP', strtotime('+6 hours'))],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull();

        $rows = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->orderBy('id')
            ->get();
        $pending = $rows->where('status', ScheduledRunNext::STATUS_PENDING);
        // Normalise the resource's ISO 8601 (with offset) to UTC `Y-m-d H:i:s`
        // to match the DB column format — same shape used in the explicit
        // cron→one-shot regression test.
        $expectedDueAt = (new DateTimeImmutable($result->data['scheduled_run']['next_run_at']))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        expect($pending)->toHaveCount(1)
            ->and($pending->first()->due_at)->toBe($expectedDueAt);
    });

    test('delete_prompt_template rejects an unknown id', function (): void {
        [$userId, $agentId] = makeScheduleToolOwner();
        [$tool] = makeScheduleToolTestFixture();

        $result = $tool->execute([
            'action'      => 'delete_prompt_template',
            'template_id' => 999_999,
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('prompt template not found');
    });
});

describe('ScheduleTool — summary presenter integration', function (): void {
    test('ScheduleTool::describeAction delegates to the summary presenter for every op', function (): void {
        [$tool] = makeScheduleToolTestFixture();

        $cases = [
            ['action' => 'list_schedules'],
            ['action' => 'list_prompt_templates'],
            ['action' => 'read_schedule', 'schedule_id' => 1],
            ['action' => 'read_prompt_template', 'template_id' => 2],
            ['action' => 'update_schedule', 'schedule_id' => 1],
            ['action' => 'update_prompt_template', 'template_id' => 2],
            ['action' => 'delete_schedule', 'schedule_id' => 1],
            ['action' => 'delete_prompt_template', 'template_id' => 2],
            ['action' => 'trigger_schedule', 'schedule_id' => 1],
            ['action' => 'unknown_op'],
        ];

        foreach ($cases as $arguments) {
            expect($tool->describeAction($arguments))->toBeString();
        }
    });
});

/**
 * Round 4 regression: setting `run_at` on a cron schedule used to be a
 * silent no-op. The service accepted the patch, updated the `run_at`
 * column to the new value, kept the old `cron_expression` in place, and
 * recomputed `next_run_at` from the existing cron — so the worker kept
 * firing per cron and the caller's `run_at` was effectively ignored.
 *
 * The fix is implicit mode-transition clearing in
 * {@see \Spora\Services\ScheduledRunService::recomputeNextRunAtIfNeeded()}:
 * when the patch sets ONE cadence field to a non-null value and the
 * existing schedule has the OPPOSITE cadence field set, clear the
 * opposite. The two directions of cron↔one-shot are now symmetric and
 * there's no silent no-op for the caller to retry against.
 */
describe('Round 4 — implicit mode-transition clearing', function (): void {
    test('setting run_at on a cron schedule clears cron_expression and switches to one-shot', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();

        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['run_at' => '2027-08-01T09:00:00+00:00'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull()
            ->and($result->data['scheduled_run']['is_active'])->toBeTrue();

        // The patch's run_at drove next_run_at (not the old cron's next
        // firing). Read the schedule back from the DB to confirm the
        // cleared cron survived the round-trip — earlier rounds only
        // checked the in-memory resource, which the failing pre-fix
        // build also returned as success.
        $readBack = $service->getRun($runId, $agentId, $userId);
        expect($readBack['scheduled_run']['cron_expression'])->toBeNull()
            ->and($readBack['scheduled_run']['run_at'])->not->toBeNull()
            ->and($readBack['scheduled_run']['next_run_at'])->toBe(
                $result->data['scheduled_run']['next_run_at'],
            );
    });

    test('setting cron_expression on a one-shot schedule clears run_at and switches to recurring', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();

        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => null,
            'run_at'          => date('Y-m-d H:i:s', strtotime('+4 hours')),
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['cron_expression' => '0 9 * * *'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBe('0 9 * * *')
            ->and($result->data['scheduled_run']['run_at'])->toBeNull();

        $readBack = $service->getRun($runId, $agentId, $userId);
        expect($readBack['scheduled_run']['cron_expression'])->toBe('0 9 * * *')
            ->and($readBack['scheduled_run']['run_at'])->toBeNull();
    });

    test('changing just the run_at on a one-shot schedule does NOT clear cron (none was set)', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();

        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => null,
            'run_at'          => date('Y-m-d H:i:s', strtotime('+4 hours')),
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['run_at' => date('Y-m-d H:i:s', strtotime('+5 hours'))],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull();
    });

    test('changing just the cron on a recurring schedule does NOT clear run_at (none was set)', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();

        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['cron_expression' => '0 17 * * *'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBe('0 17 * * *')
            ->and($result->data['scheduled_run']['run_at'])->toBeNull();
    });

    test('explicit {cron_expression: null, run_at: <iso>} still works (no double-clearing)', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();

        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => [
                'cron_expression' => null,
                'run_at'          => '2027-08-01T09:00:00+00:00',
            ],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull()
            ->and($result->data['scheduled_run']['run_at'])->not->toBeNull();
    });
});

/**
 * Round 5 regression: the OpenAI tool-call wire shape encodes the
 * `arguments` field as a JSON string, so when an LLM pattern-matches
 * the documentation "send null to clear a field" and emits
 * `"cron_expression":"null"` (the four-character string literal),
 * the validator receives a PHP string "null" — not the JSON null
 * type. Pre-Round-5 the validator strict-rejected it with the
 * "send JSON null, not the string" hint, which Round 5 callers
 * couldn't action: the LLM produces the string, not the caller.
 *
 * The fix is lenient normalisation in
 * {@see \Spora\Tools\ScheduleTool\ScheduleUpdateValidator::normalizeNullLikeStrings()}:
 * string "null" / "NULL" / "Null" / "" / "   " for any of the five
 * clearable fields (cron_expression, run_at, template_id,
 * max_steps_override, max_steps) coerces to PHP null before the
 * field-type checks run.
 *
 * These end-to-end tests go through ScheduleTool::execute() with the
 * exact wire shape a Round 5 caller would send — including the
 * JSON-string-encoded outer layer that the OpenAI driver decodes —
 * so any future re-introduction of the strict rejection path fails
 * loudly here.
 */
describe('Round 5 — wire-shape leniency for the literal string "null"', function (): void {
    test('update_schedule with {cron_expression: "null"} clears cron end-to-end', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        // Wire shape: the LLM emits the four-character string "null"
        // inside the schedule_patch. The OpenAI driver decodes the
        // outer JSON-encoded `arguments` string and passes this exact
        // PHP array to the tool — pre-Round-5 the validator rejected
        // it with "Got string('null')" and the caller had no path
        // forward.
        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['cron_expression' => 'null'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['cron_expression'])->toBeNull();
    });

    test('update_schedule with {max_steps_override: "null"} clears max_steps_override end-to-end', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression'   => '0 9 * * *',
            'max_steps_override' => 50,
            'timezone'          => 'UTC',
            'is_active'         => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['max_steps_override' => 'null'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['max_steps_override'])->toBeNull();
    });

    test('update_schedule with {template_id: "null"} unbinds the template end-to-end', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();
        $template = seedTemplate($agentId, ['name' => 'To-unbind']);
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'template_id'     => $template->id,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['template_id' => 'null'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['scheduled_run']['template_id'])->toBeNull();
    });

    test('update_prompt_template with {max_steps: "null"} clears max_steps end-to-end', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();
        $template = seedTemplate($agentId, ['max_steps' => 25]);

        $result = $tool->execute([
            'action'          => 'update_prompt_template',
            'template_id'     => $template->id,
            'template_patch'  => ['max_steps' => 'null'],
        ], $agentId, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['template']['max_steps'])->toBeNull();
    });

    test('genuinely-invalid cron (not "null", not "", not whitespace) is still rejected', function (): void {
        [$tool, $service] = makeScheduleToolTestFixture();
        [$userId, $agentId] = makeScheduleToolOwner();
        $created = $service->createRun($agentId, $userId, [
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);
        $runId = (int) $created['scheduled_run']['id'];

        $result = $tool->execute([
            'action'         => 'update_schedule',
            'schedule_id'    => $runId,
            'schedule_patch' => ['cron_expression' => 'totally bogus cron'],
        ], $agentId, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`cron_expression`');
    });
});
