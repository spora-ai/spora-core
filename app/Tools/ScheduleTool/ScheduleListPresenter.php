<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Renders slim `list_schedules` and `list_prompt_templates` payloads.
 *
 * Slim shape mirrors `AgentTool::renderAgentsList()`: the LLM only
 * needs the row id (to feed into `update_schedule(agent_id, N)` /
 * `update_prompt_template(agent_id, N)`) and a human-readable label,
 * so the full `ScheduledRunService` / `PromptTemplateService`
 * resource payload stays out of the LLM context window.
 */
final class ScheduleListPresenter
{
    public const OP_LIST_SCHEDULES = 'list_schedules';
    public const OP_LIST_TEMPLATES = 'list_prompt_templates';

    /**
     * @param  list<array<string, mixed>>|null $runs  Service-shaped rows
     *                                                 (each has `id`,
     *                                                 optional
     *                                                 `cron_expression`,
     *                                                 `run_at`,
     *                                                 `timezone`,
     *                                                 `is_active`).
     */
    public function presentSchedules(?array $runs): ToolResult
    {
        if ($runs === null) {
            return ToolResult::fail('list_schedules: agent not found.');
        }

        $slim = array_map(
            static fn(array $r): array => self::slimScheduleRow($r),
            $runs,
        );

        $content = $slim === []
            ? 'No schedules visible to the current agent.'
            : self::renderScheduleList($slim);

        return ToolResult::ok($content, ['schedules' => $slim]);
    }

    /**
     * @param  list<array<string, mixed>>|null $templates
     */
    public function presentTemplates(?array $templates): ToolResult
    {
        if ($templates === null) {
            return ToolResult::fail('list_prompt_templates: agent not found.');
        }

        $slim = array_map(
            static fn(array $t): array => self::slimTemplateRow($t),
            $templates,
        );

        $content = $slim === []
            ? 'No prompt templates visible to the current agent.'
            : self::renderTemplateList($slim);

        return ToolResult::ok($content, ['prompt_templates' => $slim]);
    }

    /**
     * @param  array<string, mixed> $r
     * @return array{schedule_id: int, summary: string, is_active: bool, next_run_at: ?string}
     */
    private static function slimScheduleRow(array $r): array
    {
        $summary = self::buildScheduleSummary($r);

        return [
            'schedule_id'  => (int) ($r['id'] ?? 0),
            'summary'      => $summary,
            'is_active'    => (bool) ($r['is_active'] ?? true),
            'next_run_at'  => isset($r['next_run_at']) && is_string($r['next_run_at'])
                ? $r['next_run_at']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed> $r
     */
    private static function buildScheduleSummary(array $r): string
    {
        $timezone = (string) ($r['timezone'] ?? 'UTC');
        $cron = isset($r['cron_expression']) && is_string($r['cron_expression']) && $r['cron_expression'] !== ''
            ? 'cron ' . $r['cron_expression']
            : null;
        $runAt = isset($r['run_at']) && is_string($r['run_at']) && $r['run_at'] !== ''
            ? 'at ' . $r['run_at']
            : null;

        $when = $cron ?? $runAt ?? 'no schedule';

        $template = isset($r['template_id']) && is_int($r['template_id'])
            ? 'template #' . $r['template_id']
            : null;
        $raw = isset($r['raw_prompt']) && is_string($r['raw_prompt']) && $r['raw_prompt'] !== ''
            ? 'raw prompt'
            : null;
        $what = $template ?? $raw ?? 'no prompt';

        return "{$when} ({$what}, {$timezone})";
    }

    /**
     * @param  array<string, mixed> $t
     * @return array{template_id: int, name: string, description: ?string, max_steps: ?int, is_active: bool}
     */
    private static function slimTemplateRow(array $t): array
    {
        return [
            'template_id' => (int) ($t['id'] ?? 0),
            'name'        => (string) ($t['name'] ?? '(unnamed)'),
            'description' => isset($t['description']) && is_string($t['description'])
                ? $t['description']
                : null,
            'max_steps'   => isset($t['max_steps']) && is_int($t['max_steps'])
                ? $t['max_steps']
                : null,
            'is_active'   => (bool) ($t['is_active'] ?? true),
        ];
    }

    /**
     * @param  list<array{schedule_id: int, summary: string, is_active: bool, next_run_at: ?string}> $slim
     */
    private static function renderScheduleList(array $slim): string
    {
        $lines = [count($slim) . ' schedule(s) visible to the current agent:'];
        foreach ($slim as $row) {
            $state = $row['is_active'] ? 'active' : 'paused';
            $lines[] = sprintf(
                '- #%d [%s] %s',
                $row['schedule_id'],
                $state,
                $row['summary'],
            );
        }
        return implode("\n", $lines);
    }

    /**
     * @param  list<array{template_id: int, name: string, description: ?string, max_steps: ?int, is_active: bool}> $slim
     */
    private static function renderTemplateList(array $slim): string
    {
        $lines = [count($slim) . ' prompt template(s) visible to the current agent:'];
        foreach ($slim as $row) {
            $state = $row['is_active'] ? 'active' : 'paused';
            $maxSteps = $row['max_steps'] === null ? '' : ' (max_steps: ' . $row['max_steps'] . ')';
            $desc = $row['description'] !== null && $row['description'] !== ''
                ? ' — ' . $row['description']
                : '';
            $lines[] = sprintf(
                '- #%d %s [%s]%s%s',
                $row['template_id'],
                $row['name'],
                $state,
                $maxSteps,
                $desc,
            );
        }
        return implode("\n", $lines);
    }
}
