<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use DateInvalidTimeZoneException;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Executes the service calls and success/failure formatting for
 * every ScheduleTool operation.
 *
 * Extracted from `ScheduleTool` to keep that class under
 * `php:S1448`'s 20-method ceiling. Owns the try/catch + success
 * formatting so the parent op methods can stay under `php:S1142`'s
 * 3-return cap.
 *
 * Mapping from operation → method:
 *
 *   read_schedule          → readSchedule()
 *   read_prompt_template   → readPromptTemplate()
 *   create_schedule        → createSchedule()
 *   create_prompt_template → createPromptTemplate()
 *   update_schedule        → updateSchedule()
 *   update_prompt_template → updatePromptTemplate()
 *   delete_schedule        → deleteSchedule()
 *   delete_prompt_template → deletePromptTemplate()
 *   trigger_schedule       → triggerSchedule()
 */
final class ScheduleOperationRunner
{
    use SchedulableTypeCoercion;

    public function __construct(
        private readonly ScheduledRunServiceInterface $scheduledRunService,
        private readonly PromptTemplateServiceInterface $promptTemplateService,
        private readonly ScheduleSummaryPresenter $summary,
    ) {}

    public function readSchedule(int $scheduleId, int $targetAgentId, int $userId): ToolResult
    {
        $result = $this->scheduledRunService->getRun($scheduleId, $targetAgentId, $userId);
        if ($result === null) {
            return ToolResult::fail('read_schedule: schedule not found or not owned by this user');
        }

        return ToolResult::ok(
            "Schedule #{$scheduleId} (agent #{$targetAgentId}): " . $this->summary->resource($result),
            $result,
        );
    }

    public function readPromptTemplate(int $templateId, int $targetAgentId, int $userId): ToolResult
    {
        $result = $this->promptTemplateService->getTemplate($templateId, $targetAgentId, $userId);
        if ($result === null) {
            return ToolResult::fail('read_prompt_template: prompt template not found or not owned by this user');
        }

        return ToolResult::ok(
            "Prompt template #{$templateId} (agent #{$targetAgentId}): "
            . (string) ($result['template']['name'] ?? '(unnamed)'),
            $result,
        );
    }

    public function createSchedule(int $targetAgentId, int $userId, array $payload): ToolResult
    {
        try {
            $result = $this->scheduledRunService->createRun($targetAgentId, $userId, $payload);
        } catch (
            \Spora\Services\Exceptions\AgentNotFoundException
            | \Spora\Services\Exceptions\PromptTemplateMissingException
            | DateInvalidTimeZoneException $e
        ) {
            return match (true) {
                $e instanceof \Spora\Services\Exceptions\AgentNotFoundException
                    => ToolResult::fail('create_schedule: schedule not found or not owned by this user'),
                default
                => ToolResult::fail('create_schedule: ' . $e->getMessage()),
            };
        }

        $resource = $result['scheduled_run'];
        $id = (int) ($resource['id'] ?? 0);

        return ToolResult::ok(
            "Created schedule #{$id} on agent #{$targetAgentId}.",
            $result,
        );
    }

    public function createPromptTemplate(int $targetAgentId, int $userId, array $payload): ToolResult
    {
        try {
            $result = $this->promptTemplateService->createTemplate($targetAgentId, $userId, $payload);
        } catch (\Spora\Services\Exceptions\AgentNotFoundException) {
            return ToolResult::fail('create_prompt_template: prompt template not found or not owned by this user');
        }

        $resource = $result['template'];
        $id = (int) ($resource['id'] ?? 0);

        return ToolResult::ok(
            "Created prompt template #{$id} on agent #{$targetAgentId}.",
            $result,
        );
    }

    public function updateSchedule(
        int $scheduleId,
        int $targetAgentId,
        int $userId,
        array $arguments,
        ScheduleUpdateValidator $updateValidator,
    ): ToolResult {
        $patch = $updateValidator->validateUpdateSchedulePatch($arguments);
        if ($patch instanceof ToolResult) {
            return $patch;
        }

        $result = $this->scheduledRunService->updateRun($scheduleId, $targetAgentId, $userId, $this->canonicaliseSchedulePatch($patch));

        return $result === null
            ? ToolResult::fail('update_schedule: schedule not found or not owned by this user')
            : ToolResult::ok(
                "Updated schedule #{$scheduleId} on agent #{$targetAgentId}.",
                $result,
            );
    }

    public function updatePromptTemplate(
        int $templateId,
        int $targetAgentId,
        int $userId,
        array $arguments,
        ScheduleUpdateValidator $updateValidator,
    ): ToolResult {
        $patch = $updateValidator->validateUpdateTemplatePatch($arguments);
        if ($patch instanceof ToolResult) {
            return $patch;
        }

        $result = $this->promptTemplateService->updateTemplate($templateId, $targetAgentId, $userId, $this->canonicaliseTemplatePatch($patch));

        return $result === null
            ? ToolResult::fail('update_prompt_template: prompt template not found or not owned by this user')
            : ToolResult::ok(
                "Updated prompt template #{$templateId} on agent #{$targetAgentId}.",
                $result,
            );
    }

    public function deleteSchedule(int $scheduleId, int $targetAgentId, int $userId): ToolResult
    {
        $ok = $this->scheduledRunService->deleteRun($scheduleId, $targetAgentId, $userId);
        if ($ok === false) {
            return ToolResult::fail('delete_schedule: schedule not found or not owned by this user');
        }

        return ToolResult::ok(
            "Deleted schedule #{$scheduleId} on agent #{$targetAgentId}.",
            ['deleted' => true, 'schedule_id' => $scheduleId, 'agent_id' => $targetAgentId],
        );
    }

    public function deletePromptTemplate(int $templateId, int $targetAgentId, int $userId): ToolResult
    {
        $ok = $this->promptTemplateService->deleteTemplate($templateId, $targetAgentId, $userId);
        if ($ok === false) {
            return ToolResult::fail('delete_prompt_template: prompt template not found or not owned by this user');
        }

        return ToolResult::ok(
            "Deleted prompt template #{$templateId} on agent #{$targetAgentId}.",
            ['deleted' => true, 'template_id' => $templateId, 'agent_id' => $targetAgentId],
        );
    }

    public function triggerSchedule(int $scheduleId, int $targetAgentId, int $userId): ToolResult
    {
        try {
            $result = $this->scheduledRunService->triggerRun($scheduleId, $targetAgentId, $userId);
        } catch (
            \Spora\Services\Exceptions\AgentNotFoundException
            | \Spora\Services\Exceptions\ScheduledRunNotFoundException
            | \Spora\Services\Exceptions\PromptTemplateMissingException $e
        ) {
            return $e instanceof \Spora\Services\Exceptions\PromptTemplateMissingException
                ? ToolResult::fail('trigger_schedule: ' . $e->getMessage())
                : ToolResult::fail('trigger_schedule: schedule not found or not owned by this user');
        }

        $taskId = (int) $result['task_id'];

        return ToolResult::ok(
            "Triggered schedule #{$scheduleId} on agent #{$targetAgentId}; new task #{$taskId}.",
            $result,
        );
    }

    /**
     * Normalise the leniently-validated schedule patch to canonical PHP
     * types (bool / int) before handing it to the service layer. The
     * DB columns expect tinyint, so a string `"25"` would otherwise
     * round-trip as 0 in MySQL.
     *
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function canonicaliseSchedulePatch(array $patch): array
    {
        if (isset($patch['is_active'])) {
            $bool = $this->coerceBool($patch['is_active']);
            if ($bool !== null) {
                $patch['is_active'] = $bool;
            }
        }
        if (array_key_exists('template_id', $patch) && $patch['template_id'] !== null) {
            $int = $this->coercePositiveInt($patch['template_id']);
            if ($int !== null) {
                $patch['template_id'] = $int;
            }
        }
        if (array_key_exists('max_steps_override', $patch) && $patch['max_steps_override'] !== null) {
            $int = $this->coercePositiveInt($patch['max_steps_override']);
            if ($int !== null) {
                $patch['max_steps_override'] = $int;
            }
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function canonicaliseTemplatePatch(array $patch): array
    {
        if (isset($patch['is_active'])) {
            $bool = $this->coerceBool($patch['is_active']);
            if ($bool !== null) {
                $patch['is_active'] = $bool;
            }
        }
        if (array_key_exists('max_steps', $patch) && $patch['max_steps'] !== null) {
            $int = $this->coercePositiveInt($patch['max_steps']);
            if ($int !== null) {
                $patch['max_steps'] = $int;
            }
        }

        return $patch;
    }
}
