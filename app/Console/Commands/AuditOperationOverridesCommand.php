<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only report of rows referencing a tool class that no longer exists.
 *
 * Two tables accumulate these and they compound: an
 * `agent_tool_operation_overrides` row is meaningless without its
 * `agent_tool_overrides` sibling, and an `agent_tools` row without either.
 * Auditing only the first reports half the problem.
 *
 * Nothing fatals on these today — every reader already guards a DB-supplied
 * `tool_class` with `class_exists`. The defect is quieter: `getOperationOverride()`
 * silently reports `effective_enabled: true, effective_requires_approval: true`
 * for a row governing nothing. Surfacing that needs a field in its return shape,
 * an API change, so this command reports rather than fixes.
 *
 * Exits non-zero when orphans are found, so it can gate a future migration.
 */
final class AuditOperationOverridesCommand extends Command
{
    public function __construct(
        private readonly Database $database,
    ) {
        parent::__construct('spora:audit-operation-overrides');
    }

    protected function configure(): void
    {
        $this->setDescription('Report tool-configuration rows that reference a tool class which no longer exists (read-only).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->database->bootDatabaseConnectionOnly();

        $reports = [
            'agent_tool_operation_overrides' => $this->report(
                'agent_tool_operation_overrides',
                static fn(array $row): string => sprintf(
                    'agent_id=%s tool_class=%s operation=%s',
                    $row['agent_id'],
                    $row['tool_class'],
                    $row['operation'],
                ),
            ),
            'agent_tools' => $this->report(
                'agent_tools',
                static fn(array $row): string => sprintf(
                    'agent_id=%s tool_class=%s',
                    $row['agent_id'],
                    $row['tool_class'],
                ),
            ),
        ];

        $total = 0;
        foreach ($reports as $table => $rows) {
            if ($rows === []) {
                $output->writeln(sprintf('  [%s] clean', $table));

                continue;
            }
            $total += count($rows);
            $output->writeln(sprintf(
                '  <comment>[%s] %d row(s) reference a missing tool class</comment>',
                $table,
                count($rows),
            ));
            foreach ($rows as $detail) {
                $output->writeln('      ' . $detail);
            }
        }

        if ($total === 0) {
            $output->writeln('<info>No orphaned tool rows.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>%d orphaned row(s) found. They are inert (every reader class_exists-guards), but a per-operation override still reports itself as governing the tool. Remove them by hand or via a dedicated migration.</comment>',
            $total,
        ));

        return Command::FAILURE;
    }

    /**
     * @param callable(array<string, mixed>): string $detail
     * @return list<string>
     */
    private function report(string $table, callable $detail): array
    {
        $missing = $this->missingToolClasses($table);
        if ($missing === []) {
            return [];
        }

        $rows = Capsule::table($table)
            ->whereIn('tool_class', $missing)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $array = (array) $row;
            $out[] = sprintf('id=%d  %s', (int) $array['id'], $detail($array));
        }

        return $out;
    }

    /**
     * One `class_exists` probe per distinct class, not per row: renaming a tool
     * orphans every row that referenced it, and probing each row separately
     * would repeat identical filesystem work hundreds of times.
     *
     * @return list<string>
     */
    private function missingToolClasses(string $table): array
    {
        $missing = [];

        foreach (Capsule::table($table)->distinct()->pluck('tool_class') as $class) {
            $class = (string) $class;
            if (!class_exists($class)) {
                $missing[] = $class;
            }
        }

        return $missing;
    }
}
