<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Widen `task_history.content`, `task_history.tool_call_payload`, and the
 * matching TEXT columns on `tasks` / `agents` / `tool_calls` from TEXT
 * (65,535 bytes) to MEDIUMTEXT (16 MiB).
 *
 * Why MEDIUMTEXT, not LONGTEXT: LONGTEXT tops out at 4 GiB and would be
 * pure overkill — these columns carry per-turn message bodies, tool call
 * JSON, and agent instructions, none of which realistically approach
 * 16 MiB. MEDIUMTEXT is the smallest upgrade that eliminates the
 * SQLSTATE 22001 truncation risk without bloating row sizes.
 *
 * SQLite has no intrinsic TEXT cap (TEXT stores any length on disk), so
 * this migration is a no-op there. Forward-only: a downgrade would
 * re-introduce the truncation bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        $driver = Capsule::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = [
            // task_history — assistant content and tool-call JSON are the
            // two columns that routinely exceed 64 KB (handover prompts,
            // long blog/research outputs, tavily search results).
            ['task_history', 'content'],
            ['task_history', 'tool_call_payload'],

            // tasks — final_response holds the assistant's terminal reply
            // for a completed task; long-form content (blog articles,
            // reports) can exceed 64 KB. user_prompt is the operator's
            // initial prompt — usually small but operators paste long
            // briefs. failure_reason / error_message are usually small
            // but stack traces can blow past 64 KB.
            ['tasks', 'user_prompt'],
            ['tasks', 'final_response'],
            ['tasks', 'failure_reason'],
            ['tasks', 'error_message'],

            // agents — long system_prompt (multi-paragraph persona
            // instructions) routinely pushes past 64 KB.
            ['agents', 'system_prompt'],

            // tool_calls — proposed_arguments / approved_arguments carry
            // the full JSON payload the agent wants to invoke; complex
            // tool calls (handover prompts, calculator expressions) can
            // exceed 64 KB. result_content / result_data capture tool
            // outputs (web search results, file contents, API responses)
            // which are frequently > 64 KB.
            ['tool_calls', 'proposed_arguments'],
            ['tool_calls', 'approved_arguments'],
            ['tool_calls', 'result_content'],
            ['tool_calls', 'result_data'],
            ['tool_calls', 'human_description'],
        ];

        foreach ($columns as [$table, $column]) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, $column)) {
                continue;
            }
            // Schema builder has no MEDIUMTEXT type for column modifications;
            // raw ALTER keeps the operation O(1) in table size on MySQL 8.0+
            // and MariaDB 10.4+ via INSTANT/INPLACE.
            Capsule::connection()->statement(
                "ALTER TABLE {$table} MODIFY {$column} MEDIUMTEXT NULL",
            );
        }
    }

    public function down(): void
    {
        // Forward-only — downgrading would re-introduce the truncation bug.
    }
};