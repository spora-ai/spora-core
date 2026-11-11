<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * The `user_info` tool becomes `principal_info`.
 *
 * The tool now answers for a group as well as for a user, so the class and
 * the wire name both change. Every table that keys a configured tool carries
 * the class name — an agent's tool list, its overrides, the per-operation
 * approval toggles and the operator's global configuration — and a row that
 * still names the old class is a row the container can no longer resolve:
 * the tool silently disappears from the agent.
 *
 * `tool_calls` is the audit trail of what actually ran, and the class that
 * ran it was `UserInfoTool`; rewriting it would make the history claim
 * something that never happened. The rows that have **not** run yet are
 * the exception: a call still awaiting approval or execution is resolved
 * by its name on the next tick, and a name that no longer resolves fails
 * the task before it records a tool result.
 */
return new class extends Migration
{
    private const OLD_CLASS = 'Spora\\Tools\\UserInfoTool';

    private const NEW_CLASS = 'Spora\\Tools\\PrincipalInfoTool';

    private const OLD_NAME = 'user_info';

    private const NEW_NAME = 'principal_info';

    /** The tables whose `tool_class` names the tool a row configures. */
    private const CLASS_TABLES = [
        'tool_configurations',
        'agent_tools',
        'agent_tool_overrides',
        'agent_tool_operation_overrides',
        'tool_user_settings',
    ];

    /** The tables that also carry the wire name of the tool. */
    private const NAME_TABLES = [
        'tool_configurations',
        'agent_tools',
    ];

    /**
     * The statuses a tool call row carries while it is still to be run.
     *
     * `APPROVED` with a stamped `executed_at` is a call that already ran,
     * so the row is only live while `executed_at` is still null.
     */
    private const IN_FLIGHT_STATUSES = ['PENDING_APPROVAL', 'AWAITING_FINAL_APPROVAL', 'APPROVED'];

    public function up(): void
    {
        $this->rename(self::OLD_CLASS, self::NEW_CLASS, self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->rename(self::NEW_CLASS, self::OLD_CLASS, self::NEW_NAME, self::OLD_NAME);
    }

    private function rename(string $fromClass, string $toClass, string $fromName, string $toName): void
    {
        $schema = Capsule::schema();

        foreach (self::CLASS_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            Capsule::table($table)
                ->where('tool_class', $fromClass)
                ->update(['tool_class' => $toClass]);
        }

        foreach (self::NAME_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            Capsule::table($table)
                ->where('tool_name', $fromName)
                ->update(['tool_name' => $toName]);
        }

        if (!$schema->hasTable('tool_calls')) {
            return;
        }

        Capsule::table('tool_calls')
            ->where('tool_name', $fromName)
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->whereNull('executed_at')
            ->update(['tool_name' => $toName, 'tool_class' => $toClass]);
    }
};
