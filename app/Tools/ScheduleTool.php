<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\Agent;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ScheduleTool\ScheduleOperationRunner;
use Spora\Tools\ScheduleTool\ScheduleSummaryPresenter;
use Spora\Tools\ScheduleTool\ScheduleToolCollaborators;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the agent inspect and manage the schedules and prompt templates
 * attached to its host agent.
 *
 * Bundled into one tool (12 operations) because schedule + template
 * are a tight pair: an LLM creating a schedule almost always
 * references a template, and pushing template authoring into a
 * separate tool would force the LLM to switch surfaces mid-flow.
 *
 * Permissions follow the existing services (`ScheduledRunService` +
 * `PromptTemplateService`):
 *   - reads (`list_*`, `read_*`) widen to principal-membership so any
 *     user who can see an agent can see its schedules/templates;
 *   - writes/deletes/trigger require the caller to control the agent's
 *     principal (owner or admin).
 *
 * Cross-agent reads/writes take an explicit `agent_id`. Omitted
 * `agent_id` resolves to the calling agent. Cross-user `schedule_id` /
 * `template_id` returns a single uniform "not found" message —
 * existence is hidden between users.
 */
#[Tool(
    name: 'schedule',
    displayName: 'Schedule',
    category: 'productivity',
    icon: 'calendar',
    description: 'Create, read, update and delete the schedules and prompt templates '
                . 'attached to this agent. '
                . 'Schedules trigger the agent on a cron or one-shot cadence using '
                . 'either a raw prompt or a saved prompt template. '
                . 'Use `list_schedules` and `list_prompt_templates` to discover row ids '
                . 'across turn boundaries, and `read_schedule` / `read_prompt_template` '
                . 'to confirm what was actually committed.',
)]
#[ToolOperation(
    name: 'list_schedules',
    description: 'List every scheduled run attached to the calling agent as a slim payload '
                . '(`schedule_id`, summary, `is_active`, `next_run_at`). '
                . 'Cross-agent reads accept an `agent_id` (numeric pk). '
                . 'Pass the row id to `read_schedule`, `update_schedule`, '
                . '`delete_schedule`, or `trigger_schedule`.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'list_prompt_templates',
    description: 'List every prompt template attached to the calling agent as a slim payload '
                . '(`template_id`, `name`, `description`, `max_steps`, `is_active`). '
                . 'Cross-agent reads accept an `agent_id` (numeric pk). '
                . 'Pass the row id to `read_prompt_template`, `update_prompt_template`, '
                . '`delete_prompt_template`, or to `create_schedule(schedule_payload: { '
                . 'template_id: N, … })` to bind a schedule to it.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'read_schedule',
    description: 'Read the full configuration of a single scheduled run by `schedule_id` '
                . '(the numeric primary key returned by `list_schedules`). '
                . 'Cross-agent reads accept an `agent_id`; omit to read a schedule on the '
                . 'calling agent. Cross-user ids return "schedule not found".',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'read_prompt_template',
    description: 'Read the full configuration of a single prompt template by `template_id` '
                . '(the numeric primary key returned by `list_prompt_templates` or by '
                . '`create_prompt_template`). Cross-agent reads accept an `agent_id`; '
                . 'omit to read a template on the calling agent. Cross-user ids return '
                . '"prompt template not found".',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_schedule',
    description: 'Create a new scheduled run from a slim payload: `schedule_payload` '
                . '(object) with `template_id` or `raw_prompt` (one required), and '
                . 'either `cron_expression` (recurring) or `run_at` (ISO 8601 one-shot, '
                . 'mutually exclusive). Optional `timezone` (IANA, defaults "UTC"), '
                . '`max_steps_override` (int 1..100, nullable), `is_active` (defaults true). '
                . 'Pass `agent_id` (numeric pk) to target a different agent; omit to '
                . 'attach the schedule to the calling agent. Returns the full schedule '
                . 'resource — call `list_schedules` afterwards only if you need to '
                . 'confirm the new row id persisted.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'create_prompt_template',
    description: 'Create a new prompt template from a slim payload: `template_payload` '
                . '(object) with `name` (1..100 chars, required), `prompt_template` '
                . '(non-empty string, required), optional `description`, `variables` '
                . '(list of `{key, default_value?}`), `max_steps` (int 1..100, nullable), '
                . '`is_active` (defaults true). Pass `agent_id` to target a different '
                . 'agent; omit to attach the template to the calling agent. Returns the '
                . 'full template resource.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'update_schedule',
    description: 'Patch a scheduled run identified by `schedule_id`. '
                . '`schedule_patch` (object) with any subset of '
                . '`template_id`, `raw_prompt`, `cron_expression`, `run_at`, `timezone`, '
                . '`max_steps_override`, `is_active`. To clear a nullable field '
                . '(`template_id`, `cron_expression`, `run_at`, `max_steps_override`) '
                . 'send `null` (JSON null is canonical; the literal string "null" is '
                . 'also accepted). '
                . 'Switching recurrence modes: setting ONE cadence field '
                . '(`cron_expression` or `run_at`) on a schedule that currently '
                . 'has the OTHER cadence set implicitly clears the other — so '
                . '`{run_at: <iso>}` switches a recurring schedule to one-shot and '
                . '`{cron_expression: <cron>}` switches a one-shot to recurring. '
                . 'Sending both populated in the same patch is rejected. '
                . 'Cross-agent updates accept `agent_id`; omit to target the calling '
                . 'agent. Returns the full schedule resource after the patch.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'update_prompt_template',
    description: 'Patch a prompt template identified by `template_id`. '
                . '`template_patch` (object) with any subset of `name`, `description`, '
                . '`prompt_template`, `variables`, `max_steps`, `is_active`. '
                . 'Send `null` to clear `max_steps`. '
                . 'Cross-agent updates accept `agent_id`; omit to target the calling '
                . 'agent. Returns the full template resource after the patch.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'delete_schedule',
    description: 'Permanently delete a scheduled run identified by `schedule_id`. '
                . 'Cross-agent deletes accept `agent_id`; omit to target the calling '
                . 'agent. Pending entries in `scheduled_runs_next` are not auto-claimed '
                . 'before the delete — call `list_schedules` and re-check the next tick '
                . 'if you need to confirm the schedule is gone.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'delete_prompt_template',
    description: 'Permanently delete a prompt template identified by `template_id`. '
                . 'Cross-agent deletes accept `agent_id`; omit to target the calling '
                . 'agent. Existing schedules that reference this template will fail at '
                . 'the next `trigger_schedule` / cron tick with a `prompt template no '
                . 'longer exists` error — re-point or disable those schedules first.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'trigger_schedule',
    description: 'Immediately fire the scheduled run identified by `schedule_id` '
                . 'regardless of its cron / `run_at` cadence. '
                . 'Returns the new `task_id` and the (now-deactivated) schedule resource '
                . 'for one-shot runs; recurring schedules remain active and pick up at '
                . 'the next cron tick. Use this for "run it now" without disabling the '
                . 'recurrence. Cross-agent triggers accept `agent_id`.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(
    name: 'agent_id',
    type: 'integer',
    description: 'Optional target for every operation that resolves a per-agent resource '
                . '(`list_schedules`, `list_prompt_templates`, `read_schedule`, '
                . '`read_prompt_template`, `create_schedule`, `create_prompt_template`, '
                . '`update_schedule`, `update_prompt_template`, `delete_schedule`, '
                . '`delete_prompt_template`, `trigger_schedule`). Omit to operate on the '
                . 'calling agent. Cross-user ids return "agent not found".',
    required: false,
)]
#[ToolParameter(
    name: 'schedule_id',
    type: 'integer',
    description: 'Numeric primary key for `read_schedule`, `update_schedule`, '
                . '`delete_schedule`, `trigger_schedule`. Returned by `list_schedules` '
                . 'and `create_schedule`. Ignored by every other operation.',
    required: false,
)]
#[ToolParameter(
    name: 'template_id',
    type: 'integer',
    description: 'Numeric primary key for `read_prompt_template`, `update_prompt_template`, '
                . '`delete_prompt_template`. Returned by `list_prompt_templates` and '
                . '`create_prompt_template`. Ignored by every other operation.',
    required: false,
)]
#[ToolParameter(
    name: 'schedule_payload',
    type: 'object',
    description: 'ONLY for `create_schedule`. Slim payload: top-level `template_id` (int) '
                . 'OR `raw_prompt` (string, one required), `cron_expression` (5-field cron) '
                . 'OR `run_at` (ISO 8601, one required — mutually exclusive), '
                . 'optional `timezone` (IANA, default "UTC"), `max_steps_override` '
                . '(int 1..100, nullable), `is_active` (bool, default true). '
                . 'Pass `agent_id` separately to target a different agent. '
                . self::IGNORED_BY_OTHER_OPERATIONS,
    required: false,
)]
#[ToolParameter(
    name: 'template_payload',
    type: 'object',
    description: 'ONLY for `create_prompt_template`. Slim payload: top-level `name` '
                . '(1..100 chars, required), `prompt_template` (non-empty string, required), '
                . 'optional `description`, `variables` (list of `{key, default_value?}`), '
                . '`max_steps` (int 1..100, nullable), `is_active` (bool, default true). '
                . 'Pass `agent_id` separately to target a different agent. '
                . self::IGNORED_BY_OTHER_OPERATIONS,
    required: false,
)]
#[ToolParameter(
    name: 'schedule_patch',
    type: 'object',
    description: 'ONLY for `update_schedule`. Partial object with any subset of '
                . '`template_id`, `raw_prompt`, `cron_expression`, `run_at`, `timezone`, '
                . '`max_steps_override`, `is_active`. To clear a nullable field '
                . '(`template_id`, `cron_expression`, `run_at`, `max_steps_override`) '
                . 'send `null` (JSON null is canonical; the literal string "null" is '
                . 'also accepted). Setting ONE cadence field on a schedule that '
                . 'has the OTHER cadence set implicitly clears the other — '
                . 'never populate both in the same patch. '
                . self::IGNORED_BY_OTHER_OPERATIONS,
    required: false,
)]
#[ToolParameter(
    name: 'template_patch',
    type: 'object',
    description: 'ONLY for `update_prompt_template`. Partial object with any subset of '
                . '`name`, `description`, `prompt_template`, `variables`, `max_steps`, '
                . '`is_active`. Send `null` to clear `max_steps`. '
                . self::IGNORED_BY_OTHER_OPERATIONS,
    required: false,
)]
final class ScheduleTool extends AbstractTool
{
    public const SCHEDULE_NOT_FOUND          = 'schedule not found or not owned by this user';
    public const PROMPT_TEMPLATE_NOT_FOUND   = 'prompt template not found or not owned by this user';

    // Standard "Ignored by every other operation." suffix appended to
    // every per-operation ToolParameter description. Surface used in the
    // LLM-facing parameter schema; constant kept public so the build
    // pipeline can audit it.
    public const IGNORED_BY_OTHER_OPERATIONS = 'Ignored by every other operation.';

    private readonly ScheduleToolCollaborators $collaborators;

    private readonly ScheduledRunServiceInterface $scheduledRunService;
    private readonly PromptTemplateServiceInterface $promptTemplateService;
    private readonly PrincipalResolver $principalResolver;
    private readonly PrincipalService $principalService;
    private readonly ScheduleSummaryPresenter $summary;
    private readonly ScheduleOperationRunner $runner;

    public function __construct(
        ScheduledRunServiceInterface $scheduledRunService,
        PromptTemplateServiceInterface $promptTemplateService,
        ?ScheduleToolCollaborators $collaborators = null,
        ?PrincipalResolver $principalResolver = null,
        ?PrincipalService $principalService = null,
        ?ScheduleOperationRunner $runner = null,
    ) {
        $this->scheduledRunService = $scheduledRunService;
        $this->promptTemplateService = $promptTemplateService;

        $collaborators ??= new ScheduleToolCollaborators(
            principalResolver: $principalResolver ?? new PrincipalResolver(),
            principalService: $principalService,
        );
        $this->collaborators = $collaborators;

        $this->principalResolver = $collaborators->principalResolver();
        $this->principalService = $collaborators->principalService();
        $this->summary = $collaborators->summary();
        $this->runner = $runner ?? new ScheduleOperationRunner(
            $scheduledRunService,
            $promptTemplateService,
            $this->summary,
        );
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_schedules'         => $this->listSchedules($agentId, $userId, $arguments),
            'list_prompt_templates'  => $this->listTemplates($agentId, $userId, $arguments),
            'read_schedule'          => $this->readSchedule($agentId, $userId, $arguments),
            'read_prompt_template'   => $this->readTemplate($agentId, $userId, $arguments),
            'create_schedule'        => $this->createSchedule($agentId, $userId, $arguments),
            'create_prompt_template' => $this->createTemplate($agentId, $userId, $arguments),
            'update_schedule'        => $this->updateSchedule($agentId, $userId, $arguments),
            'update_prompt_template' => $this->updateTemplate($agentId, $userId, $arguments),
            'delete_schedule'        => $this->deleteSchedule($agentId, $userId, $arguments),
            'delete_prompt_template' => $this->deleteTemplate($agentId, $userId, $arguments),
            'trigger_schedule'       => $this->triggerSchedule($agentId, $userId, $arguments),
            default                  => ToolResult::fail("Invalid action '{$operation}'."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $agentLabel = $this->summary->targetAgentLabel($arguments);

        return match ($operation) {
            'list_schedules'           => "List scheduled runs for {$agentLabel}.",
            'list_prompt_templates'    => "List prompt templates for {$agentLabel}.",
            'read_schedule'            => sprintf(
                'Read scheduled run (%s, %s).',
                $this->summary->scheduleIdLabel($arguments),
                $agentLabel,
            ),
            'read_prompt_template'     => sprintf(
                'Read prompt template (%s, %s).',
                $this->summary->templateIdLabel($arguments),
                $agentLabel,
            ),
            'create_schedule'          => $this->summary->createSchedule($arguments, $agentLabel),
            'create_prompt_template'   => $this->summary->createPromptTemplate($arguments, $agentLabel),
            'update_schedule'          => sprintf(
                'Update scheduled run (%s, %s).',
                $this->summary->scheduleIdLabel($arguments),
                $agentLabel,
            ),
            'update_prompt_template'   => sprintf(
                'Update prompt template (%s, %s).',
                $this->summary->templateIdLabel($arguments),
                $agentLabel,
            ),
            'delete_schedule'          => sprintf(
                'Delete scheduled run (%s, %s, destructive).',
                $this->summary->scheduleIdLabel($arguments),
                $agentLabel,
            ),
            'delete_prompt_template'   => sprintf(
                'Delete prompt template (%s, %s, destructive).',
                $this->summary->templateIdLabel($arguments),
                $agentLabel,
            ),
            'trigger_schedule'         => sprintf(
                'Trigger scheduled run now (%s, %s).',
                $this->summary->scheduleIdLabel($arguments),
                $agentLabel,
            ),
            default                    => "Schedule tool: {$operation}",
        };
    }

    private function listSchedules(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $resolved = $this->resolveTargetAgentId($userId, $agentId, $arguments);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $runs = $this->scheduledRunService->getRunsForAgent($resolved, $userId ?? 0);
        return $this->collaborators->listPresenter()->presentSchedules($runs);
    }

    private function listTemplates(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $resolved = $this->resolveTargetAgentId($userId, $agentId, $arguments);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $templates = $this->promptTemplateService->getTemplatesForAgent($resolved, $userId ?? 0);
        return $this->collaborators->listPresenter()->presentTemplates($templates);
    }

    private function readSchedule(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $scheduleId = $this->collaborators->targetResolver()->parseScheduleId($arguments['schedule_id'] ?? null);
        if ($scheduleId instanceof ToolResult) {
            return $scheduleId;
        }

        return $this->runner->readSchedule($scheduleId, $targetAgentId, $userId ?? 0);
    }

    private function readTemplate(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $templateId = $this->collaborators->targetResolver()->parseTemplateId($arguments['template_id'] ?? null);
        if ($templateId instanceof ToolResult) {
            return $templateId;
        }

        return $this->runner->readPromptTemplate($templateId, $targetAgentId, $userId ?? 0);
    }

    private function createSchedule(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $payload = $this->collaborators->payloadValidator()->validateCreateSchedule($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->runner->createSchedule($targetAgentId, $userId ?? 0, $payload);
    }

    private function createTemplate(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $payload = $this->collaborators->payloadValidator()->validateCreatePromptTemplate($arguments);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->runner->createPromptTemplate($targetAgentId, $userId ?? 0, $payload);
    }

    private function updateSchedule(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $scheduleId = $this->collaborators->targetResolver()->parseScheduleId($arguments['schedule_id'] ?? null);
        if ($scheduleId instanceof ToolResult) {
            return $scheduleId;
        }

        return $this->runner->updateSchedule(
            $scheduleId,
            $targetAgentId,
            $userId ?? 0,
            $arguments,
            $this->collaborators->updateValidator(),
        );
    }

    private function updateTemplate(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $templateId = $this->collaborators->targetResolver()->parseTemplateId($arguments['template_id'] ?? null);
        if ($templateId instanceof ToolResult) {
            return $templateId;
        }

        return $this->runner->updatePromptTemplate(
            $templateId,
            $targetAgentId,
            $userId ?? 0,
            $arguments,
            $this->collaborators->updateValidator(),
        );
    }

    private function deleteSchedule(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $scheduleId = $this->collaborators->targetResolver()->parseScheduleId($arguments['schedule_id'] ?? null);
        if ($scheduleId instanceof ToolResult) {
            return $scheduleId;
        }

        return $this->runner->deleteSchedule($scheduleId, $targetAgentId, $userId ?? 0);
    }

    private function deleteTemplate(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $templateId = $this->collaborators->targetResolver()->parseTemplateId($arguments['template_id'] ?? null);
        if ($templateId instanceof ToolResult) {
            return $templateId;
        }

        return $this->runner->deletePromptTemplate($templateId, $targetAgentId, $userId ?? 0);
    }

    private function triggerSchedule(int $agentId, ?int $userId, array $arguments): ToolResult
    {
        $targetAgentId = $this->resolveWriteTargetAgentId($userId, $agentId, $arguments);
        if ($targetAgentId instanceof ToolResult) {
            return $targetAgentId;
        }

        $scheduleId = $this->collaborators->targetResolver()->parseScheduleId($arguments['schedule_id'] ?? null);
        if ($scheduleId instanceof ToolResult) {
            return $scheduleId;
        }

        return $this->runner->triggerSchedule($scheduleId, $targetAgentId, $userId ?? 0);
    }

    /**
     * Resolution for `list_*` + reads + writes/deletes/trigger with
     * relaxed visibility. Cross-user ids widen to principal-membership
     * (group members can address agents they belong to); the service
     * layer still enforces tighter ownership for writes.
     *
     * @return int|ToolResult
     */
    private function resolveTargetAgentId(?int $userId, int $callingAgentId, array $arguments): int|ToolResult
    {
        if (!array_key_exists('agent_id', $arguments)) {
            return $callingAgentId;
        }

        return $this->resolveVisibleAgentId($userId, $arguments['agent_id']);
    }

    /**
     * Resolution for write/delete/trigger: callers must control the
     * agent's principal. We never silently fall back to the calling
     * agent when an explicit `agent_id` is supplied.
     *
     * @return int|ToolResult
     */
    private function resolveWriteTargetAgentId(?int $userId, int $callingAgentId, array $arguments): int|ToolResult
    {
        if (!array_key_exists('agent_id', $arguments)) {
            return $callingAgentId;
        }

        $resolved = $this->resolveVisibleAgentId($userId, $arguments['agent_id']);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $result = $resolved;
        if ($userId !== null
            && !$this->principalService->callerControlsPrincipal($userId, $this->principalIdOfAgent($resolved))
        ) {
            $result = ToolResult::fail('agent not found or not owned by this user.');
        }

        return $result;
    }

    /**
     * Coerce + DB lookup + visibility check for an explicit cross-user
     * `agent_id`. Walks three gates (positive integer, agent exists,
     * user can see it) via a single typed `$result` variable.
     *
     * @return int|ToolResult
     */
    private function resolveVisibleAgentId(?int $userId, mixed $raw): int|ToolResult
    {
        $result = null;

        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            $result = ToolResult::fail('`agent_id` must be a positive integer.');
        } elseif (($resolvedId = (int) $raw) <= 0) {
            $result = ToolResult::fail('`agent_id` must be a positive integer.');
        } else {
            $agent = Agent::query()->where('id', $resolvedId)->first();
            $visible = $agent !== null
                && ($userId === null || $this->principalResolver->isVisibleTo($resolvedId, $userId));
            $result = $visible
                ? $resolvedId
                : ToolResult::fail('agent not found.');
        }

        return $result;
    }

    private function principalIdOfAgent(int $agentId): int
    {
        $agent = Agent::query()->where('id', $agentId)->first(['principal_id']);
        return (int) ($agent->principal_id ?? 0);
    }
}
