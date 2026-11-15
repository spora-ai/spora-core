<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The step-cap columns widen to what the ceiling allows.
 *
 * Every column that holds a cap was a `TINYINT`: `agents.max_steps` and
 * `tasks.max_steps` unsigned, and `agent_prompt_templates.max_steps` and
 * `scheduled_runs.max_steps_override` signed. The signed pair tops out at
 * 127 and the unsigned pair at 255 — and the ceiling (`SPORA_MAX_STEPS`)
 * defaults to 200 and is meant to be raised for long-running agentic
 * workflows.
 *
 * So a template asking for 150 steps passed validation and then failed to
 * store on MySQL and MariaDB, while SQLite — which stores every integer it
 * is given — took it. The same divergence would have appeared on the agent
 * and task columns the moment an operator raised the ceiling past 255.
 *
 * `SMALLINT UNSIGNED` holds 65535, which is the ceiling
 * `Spora\Services\AgentStepCap` clamps to.
 *
 * The nullability and the default of each column are kept as they were,
 * except that the agent and task columns' default follows the application
 * default: a cap of ten was the old application default, and a row
 * inserted without the column would still have carried it.
 *
 * Idempotency: gated on the column's reported type, so a re-run after a
 * partial failure is a no-op.
 */
return new class extends Migration {
    /**
     * The columns that hold a cap, and the table each belongs to.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'agents' => 'max_steps',
        'tasks' => 'max_steps',
        'agent_prompt_templates' => 'max_steps',
        'scheduled_runs' => 'max_steps_override',
    ];

    /**
     * The columns that are `NOT NULL` with a default.
     *
     * @var array<string, int>
     */
    private const WITH_DEFAULT = [
        'agents.max_steps' => 100,
        'tasks.max_steps' => 100,
    ];

    public function up(): void
    {
        $schema = Capsule::schema();

        foreach (self::COLUMNS as $table => $column) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, $column)) {
                continue;
            }

            if ($this->isWidened($schema, $table, $column)) {
                continue;
            }

            $default = self::WITH_DEFAULT[$table . '.' . $column] ?? null;

            $schema->table($table, static function (Blueprint $blueprint) use ($column, $default): void {
                $definition = $blueprint->unsignedSmallInteger($column);

                if ($default === null) {
                    $definition->nullable();
                } else {
                    $definition->default($default);
                }

                $definition->change();
            });
        }
    }

    public function down(): void
    {
        // The widened columns are not narrowed back: a cap above the
        // narrow range cannot be stored, and the ceiling it came from is
        // the operator's to lower first.
    }

    /**
     * Whether the column already holds a `SMALLINT`.
     */
    private function isWidened(\Illuminate\Database\Schema\Builder $schema, string $table, string $column): bool
    {
        foreach ($schema->getColumns($table) as $reported) {
            if ($reported['name'] === $column) {
                return str_contains(strtolower((string) $reported['type']), 'smallint');
            }
        }

        return false;
    }
};
