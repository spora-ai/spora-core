<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Rename the `handover` tool to `sub_agent` in `tool_calls` rows.
 *
 * The tool class was renamed from `Spora\Tools\HandoverTool` to
 * `Spora\Tools\SubAgentTool` (PR #255 plumbing + this rename), so any
 * rows written by the old tool name need their `tool_name` and
 * `tool_class` columns rewritten before the orchestrator's dispatch
 * lookup can find the new class on the next tick.
 *
 * Hard break: no alias for `tool_name = 'handover'` is registered
 * (per the user's explicit "hard break" choice — see the plan), so a
 * pre-upgrade in-flight call with the old tool_name will fail at the
 * orchestrator dispatch layer. Operators upgrade during a low-traffic
 * window. `down()` restores the old values so a downgrade works.
 *
 * Scope: only `tool_calls.tool_name` and `tool_calls.tool_class` are
 * rewritten. `tasks.data.handover.*` JSON is intentionally left
 * untouched — the `handover` key on `tasks.data` is the semantic event
 * breadcrumb (source completed because it handed off to a target),
 * not the tool name. Existing chats depend on that key for the green
 * "Handed off to …" pill rendering and the frontend deep-link, so a
 * JSON migration would churn chat history for no benefit. The
 * `data.handover` field describes the EVENT (handover op fired), not
 * the tool identifier (now `sub_agent`).
 *
 * Idempotency: `up()` only rewrites rows where `tool_name = 'handover'`
 * AND `tool_class = 'Spora\Tools\HandoverTool'`, so a re-run against an
 * already-migrated DB is a no-op. Same for `down()`.
 */
return new class extends Migration {
    public function up(): void
    {
        Capsule::table('tool_calls')
            ->where('tool_name', 'handover')
            ->where('tool_class', 'Spora\\Tools\\HandoverTool')
            ->update([
                'tool_name'  => 'sub_agent',
                'tool_class' => 'Spora\\Tools\\SubAgentTool',
            ]);
    }

    public function down(): void
    {
        Capsule::table('tool_calls')
            ->where('tool_name', 'sub_agent')
            ->where('tool_class', 'Spora\\Tools\\SubAgentTool')
            ->update([
                'tool_name'  => 'handover',
                'tool_class' => 'Spora\\Tools\\HandoverTool',
            ]);
    }
};
