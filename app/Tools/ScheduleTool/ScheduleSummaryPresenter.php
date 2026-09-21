<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

/**
 * Renders human-readable summaries used in:
 *   - the approval-UI description (ScheduleTool::describeAction)
 *   - the post-execution `result_content` message (ScheduleTool::execute)
 *
 * Kept out of ScheduleTool to keep the umbrella class under the
 * SonarCloud S1448 20-method ceiling.
 */
final class ScheduleSummaryPresenter
{
    public const NO_SCHEDULE_ID = 'no schedule_id';
    public const NO_TEMPLATE_ID = 'no template_id';
    public const CALLING_AGENT  = 'calling agent';

    /**
     * @param  array<string, mixed> $arguments
     */
    public function targetAgentLabel(array $arguments): string
    {
        if (isset($arguments['agent_id']) && is_numeric($arguments['agent_id']) && (int) $arguments['agent_id'] > 0) {
            return 'agent #' . (int) $arguments['agent_id'];
        }
        return self::CALLING_AGENT;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function scheduleIdLabel(array $arguments): string
    {
        if (isset($arguments['schedule_id']) && is_numeric($arguments['schedule_id']) && (int) $arguments['schedule_id'] > 0) {
            return 'schedule #' . (int) $arguments['schedule_id'];
        }
        return self::NO_SCHEDULE_ID;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function templateIdLabel(array $arguments): string
    {
        if (isset($arguments['template_id']) && is_numeric($arguments['template_id']) && (int) $arguments['template_id'] > 0) {
            return 'template #' . (int) $arguments['template_id'];
        }
        return self::NO_TEMPLATE_ID;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function createSchedule(array $arguments, string $agentLabel): string
    {
        $payload = is_array($arguments['schedule_payload'] ?? null) ? $arguments['schedule_payload'] : [];
        return "Create schedule for {$agentLabel}: {$this->summariseScheduleWhen($payload)} with {$this->summariseSchedulePrompt($payload)}.";
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function createPromptTemplate(array $arguments, string $agentLabel): string
    {
        $payload = is_array($arguments['template_payload'] ?? null) ? $arguments['template_payload'] : [];
        $name = isset($payload['name']) && is_string($payload['name']) ? '"' . $payload['name'] . '"' : '"(unnamed)"';
        return "Create prompt template for {$agentLabel}: {$name}.";
    }

    /**
     * Compact rendering of a schedule resource returned by the read path.
     *
     * @param array<string, mixed> $result ToolResult payload (expected to wrap `scheduled_run`).
     */
    public function resource(array $result): string
    {
        $run = $result['scheduled_run'] ?? null;
        if (!is_array($run)) {
            return '(empty)';
        }
        $when = $this->summariseScheduleWhen($run);
        $prompt = $this->summariseSchedulePrompt($run);
        $state = !empty($run['is_active']) ? 'active' : 'paused';
        $tz = (string) ($run['timezone'] ?? 'UTC');
        return "{$when}, {$prompt}, {$state}, {$tz}";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summariseScheduleWhen(array $payload): string
    {
        $cron = $payload['cron_expression'] ?? null;
        if (is_string($cron) && $cron !== '') {
            return 'cron "' . $cron . '"';
        }
        $runAt = $payload['run_at'] ?? null;
        if (is_string($runAt) && $runAt !== '') {
            return 'one-shot "' . $runAt . '"';
        }
        return 'unspecified cadence';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summariseSchedulePrompt(array $payload): string
    {
        $templateId = $payload['template_id'] ?? null;
        if (is_int($templateId)) {
            return 'template #' . $templateId;
        }
        return 'raw_prompt';
    }
}
