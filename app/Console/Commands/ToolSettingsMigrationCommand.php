<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Core\Database;
use Spora\Core\SecurityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-time migration: encrypt all existing plain-text tool settings.
 *
 * Run once: php bin/spora tool-settings:migrate-encryption
 */
final class ToolSettingsMigrationCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly SecurityManagerInterface $security,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct('tool-settings:migrate-encryption');
    }

    protected function configure(): void
    {
        $this->setDescription('Encrypt all existing plain-text tool settings (one-time migration).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->database->bootDatabaseConnectionOnly();

        $output->writeln('<info>Starting tool settings encryption migration...</info>');

        $counts = [
            'tool_configurations' => $this->migrateTable($output, 'tool_configurations'),
            'tool_user_settings'  => $this->migrateTable($output, 'tool_user_settings'),
            'agent_tool_overrides' => $this->migrateTable($output, 'agent_tool_overrides'),
        ];

        $total = array_sum($counts);
        $output->writeln(sprintf('<info>Migration complete. Encrypted %d record(s) across %d tables.</info>', $total, count($counts)));
        $this->logger->info('Tool settings encryption migration complete', $counts);

        return Command::SUCCESS;
    }

    /**
     * @return int Number of records encrypted
     */
    private function migrateTable(OutputInterface $output, string $table): int
    {
        $rows = Capsule::table($table)->select(['id', 'settings'])->whereNotNull('settings')->where('settings', '!=', '')->get();

        if ($rows->isEmpty()) {
            $output->writeln(sprintf('  [%s] No records to migrate.', $table));
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $raw = (string) $row->settings;

            // Skip if already encrypted
            if ($this->security->looksEncrypted($raw)) {
                continue;
            }

            // Parse plain JSON
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $output->writeln(sprintf('  <comment>[%s] Skipping id=%d: invalid JSON</comment>', $table, $row->id));
                continue;
            }

            // Encrypt the whole blob
            $encrypted = $this->security->encrypt(json_encode($decoded, JSON_THROW_ON_ERROR))->toStorageString();

            Capsule::table($table)->where('id', $row->id)->update(['settings' => $encrypted]);
            $count++;
        }

        $output->writeln(sprintf('  [%s] Encrypted %d record(s).', $table, $count));
        return $count;
    }
}
