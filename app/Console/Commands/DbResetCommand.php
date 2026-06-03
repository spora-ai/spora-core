<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Closure;
use PDO;
use Spora\Core\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Wipes the configured database and clears the schema stamp.
 *
 * Driver-aware (reads SPORA_DB_DRIVER / config.db_driver):
 *  - sqlite (default): deletes storage/database.sqlite (or the path in db_path).
 *  - mysql: DROP DATABASE + CREATE DATABASE on SPORA_DB_NAME.
 *
 * The MySQL path ALWAYS requires --force (or a typed "yes" at the prompt),
 * because it hits a shared server rather than a local file.
 */
#[AsCommand(
    name: 'db:reset',
    description: 'Wipe the database and clear the schema stamp. SQLite: deletes the file. MySQL: DROP + CREATE DATABASE.',
)]
final class DbResetCommand extends Command
{
    /**
     * @param Closure(string $dsn, string $user, string $password, array<int, mixed> $options): PDO $pdoFactory
     *        Builds the server-level PDO connection used by the MySQL branch.
     *        Injected so tests can swap in a mock PDO without spinning up a real server.
     */
    public function __construct(
        private readonly Database $database,
        private readonly ?Closure $pdoFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the interactive confirm prompt')
            ->setHelp(<<<HELP
Wipes the database Spora is configured to use, then clears <info>storage/.schema_stamp</info>
so the installer will re-run all migrations on the next boot.

The DB driver is read from the same resolution chain Spora uses at runtime:
built-in defaults → <comment>config.php</comment> → <comment>SPORA_*</comment> env vars.

<comment>SQLite (default):</comment> unlinks the SQLite file at <info>storage/database.sqlite</info>
        (or the path in <comment>db_path</comment>). Prompts before deleting a
        non-empty file unless <info>--force</info> is given.

<comment>MySQL:</comment>  runs <info>DROP DATABASE IF EXISTS</info> + <info>CREATE DATABASE</info>
        on the configured <comment>SPORA_DB_NAME</comment>. The MySQL path
        <error>always</error> requires <info>--force</info> (or the literal answer
        "yes" typed at the prompt) because it cannot be undone on a shared server.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $config   = $this->database->getConfig();
        $driver   = strtolower((string) ($config['db_driver'] ?? 'sqlite'));
        $force    = (bool) $input->getOption('force');
        $stampPath = $this->database->getStampPath();

        try {
            if ($driver === 'mysql') {
                $exit = $this->resetMysql($io, $force, $config);
            } else {
                $exit = $this->resetSqlite($io, $force, $config);
            }
        } catch (Throwable $e) {
            $io->error('Reset failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($exit !== Command::SUCCESS) {
            return $exit;
        }

        if ($stampPath !== null && is_file($stampPath)) {
            unlink($stampPath);
            $io->writeln('Cleared schema stamp.');
        }

        $io->success("Database reset complete. Run <info>php bin/spora spora:install</info> to apply migrations.");

        return Command::SUCCESS;
    }

    /**
     * SQLite branch: unlink + touch the local file. A fresh `touch`'d file is
     * always 0 bytes, so a non-empty file is a reliable signal of "user data".
     *
     * @param array<string, mixed> $config
     */
    private function resetSqlite(SymfonyStyle $io, bool $force, array $config): int
    {
        $dbPath = $config['db_path'] ?? (BASE_PATH . '/storage/database.sqlite');

        if (!$force && is_file($dbPath) && filesize($dbPath) > 0) {
            $io->writeln("storage/database.sqlite already exists and is non-empty.");
            $io->writeln("  Path: {$dbPath}  (size: " . filesize($dbPath) . " bytes)");
            if (!$io->confirm('Wipe and re-migrate?', false)) {
                $io->writeln('Aborted. No changes made. (Pass --force to skip the prompt in non-interactive runs.)');
                return Command::FAILURE;
            }
        }

        if (is_file($dbPath)) {
            unlink($dbPath);
            $io->writeln("Deleted {$dbPath}");
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        touch($dbPath);
        $io->writeln("Created fresh SQLite database.");

        return Command::SUCCESS;
    }

    /**
     * MySQL branch: connect to the server's system DB, then DROP + CREATE the
     * configured db_name. Requires --force (or typed "yes") because it's
     * irreversible on a shared server.
     *
     * @param array<string, mixed> $config
     */
    private function resetMysql(SymfonyStyle $io, bool $force, array $config): int
    {
        $host     = $config['db_host']     ?? null;
        $port     = (int) ($config['db_port'] ?? 3306);
        $name     = $config['db_name']     ?? null;
        $user     = $config['db_user']     ?? null;
        $password = $config['db_password'] ?? null;

        $missing = [];
        foreach (['db_host' => 'SPORA_DB_HOST', 'db_name' => 'SPORA_DB_NAME', 'db_user' => 'SPORA_DB_USER', 'db_password' => 'SPORA_DB_PASSWORD'] as $key => $env) {
            if (empty($config[$key])) {
                $missing[] = $env;
            }
        }
        if ($missing) {
            $io->error('Cannot reset MySQL: missing config: ' . implode(', ', $missing));
            $io->writeln('Set these in <comment>.env</comment> (or <comment>config.php</comment>) and re-run.');
            return Command::FAILURE;
        }

        if (!$force) {
            $io->writeln("About to <error>DROP DATABASE</error> `{$name}` on {$host}:{$port} and recreate it empty.");
            $io->writeln('This is irreversible.');
            // ask() returns the default ('') in non-interactive mode, so non-TTY
            // runs fail closed even without the explicit comparison below.
            $answer = $io->ask('Type "yes" to continue, anything else to abort', '');
            if ($answer !== 'yes') {
                $io->writeln('Aborted. No changes made. (Pass --force to skip the prompt in non-interactive runs.)');
                return Command::FAILURE;
            }
        }

        // Connect to the *server* (not the user's DB) so we can DROP it.
        // The Eloquent connection bootstrapped below targets the user's DB
        // and is unused for the wipe — we need a separate PDO handle.
        $this->database->bootDatabaseConnectionOnly();

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdoFactory = $this->pdoFactory ?? static fn(string $d, string $u, string $p, array $o): PDO => new PDO($d, $u, $p, $o);
        $pdo = $pdoFactory($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        $io->writeln("Dropped database `{$name}`.");

        $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $io->writeln("Created database `{$name}` (utf8mb4 / utf8mb4_unicode_ci).");

        return Command::SUCCESS;
    }
}
