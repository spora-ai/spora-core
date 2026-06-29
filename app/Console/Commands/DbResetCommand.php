<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Closure;
use PDO;
use Spora\Core\Database;
use Spora\Core\Paths;
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
 * because it hits a shared server rather than a local file. The MySQL
 * db_name is also validated against MySQL's identifier rules before it
 * is interpolated into the DDL — DROP/CREATE DATABASE cannot be
 * parameterised, so rejection is the only safe path for unusual inputs.
 */
#[AsCommand(
    name: 'db:reset',
    description: 'Wipe the database and clear the schema stamp. SQLite: deletes the file. MySQL: DROP + CREATE DATABASE.',
)]
final class DbResetCommand extends Command
{
    /**
     * @param Closure(string $dsn, string $user, string $password, array<int, mixed> $options): PDO $pdoFactory
     *        Injected so tests can swap in a mock PDO without spinning up a real MySQL server.
     */
    public function __construct(
        private readonly Database $database,
        private readonly ?Closure $pdoFactory = null,
        private readonly ?Paths $paths = null,
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
     * A fresh `touch`'d file is always 0 bytes, so `filesize() > 0` is a
     * reliable "this has user data" signal — we only prompt in that case.
     *
     * @param array<string, mixed> $config
     */
    private function resetSqlite(SymfonyStyle $io, bool $force, array $config): int
    {
        // `??` alone lets an explicit `db_path => null` (or empty string) slip
        // through to the filesystem calls and explode — normalise to a real path.
        $rawPath  = $config['db_path'] ?? null;
        $dbPath   = (is_string($rawPath) && $rawPath !== '' && $rawPath !== ':memory:')
            ? $rawPath
            : ($this->paths?->storage('database.sqlite') ?? BASE_PATH . '/storage/database.sqlite');

        if (!$force && is_file($dbPath) && filesize($dbPath) > 0) {
            $io->writeln("{$dbPath} already exists and is non-empty.");
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
     * @param array<string, mixed> $config
     */
    private function resetMysql(SymfonyStyle $io, bool $force, array $config): int
    {
        $name = (string) ($config['db_name'] ?? '');
        $host = $config['db_host'] ?? null;
        $port = (int) ($config['db_port'] ?? 3306);
        $user     = $config['db_user']     ?? null;
        $password = $config['db_password'] ?? null;

        $precondition = $this->checkMysqlPreconditions($io, $force, $name, $host, $port, $config);
        if ($precondition !== null) {
            return $precondition;
        }

        // Skip booting the Eloquent connection — it targets the user's DB and
        // would fail precisely when the DB is missing/corrupt (the scenario
        // `db:reset` is meant to recover from). Connect to the server instead.
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdoFactory = $this->pdoFactory ?? static fn(string $d, string $u, string $p, array $o): PDO => new PDO($d, $u, $p, $o);
        $pdo = $pdoFactory($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // db_name is already validated against MySQL's identifier rules by
        // checkMysqlPreconditions() (DROP/CREATE DATABASE can't be parameterised),
        // so it can be interpolated directly into the DDL with no further
        // escaping.
        $quoted = '`' . $name . '`';
        $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
        $io->writeln("Dropped database {$quoted}.");

        $pdo->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $io->writeln("Created database {$quoted} (utf8mb4 / utf8mb4_unicode_ci).");

        return Command::SUCCESS;
    }

    /**
     * Run the precondition gates for the MySQL reset path: identifier check,
     * missing-config scan, and interactive confirmation. Returns a failure
     * exit code on the first violation, or null when it is safe to proceed.
     *
     * @param array<string, mixed> $config
     */
    private function checkMysqlPreconditions(
        SymfonyStyle $io,
        bool $force,
        string $name,
        ?string $host,
        int $port,
        array $config,
    ): ?int {
        foreach ([
            $this->validateMysqlName($io, $name),
            $this->validateMysqlConfig($io, $config),
            $this->confirmMysqlResetPrecondition($io, $force, $name, $host, $port),
        ] as $result) {
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Validate db_name BEFORE the missing-config scan. An injection-shaped
     * db_name is the most dangerous input we can receive — refusing it
     * first means it never reaches the rest of the config-reading path,
     * and the error message can name the bad value unambiguously.
     */
    private function validateMysqlName(SymfonyStyle $io, string $name): ?int
    {
        if (!preg_match('/^[\x{0080}-\x{FFFF}a-zA-Z_\$][\x{0080}-\x{FFFF}a-zA-Z0-9_\$]{0,63}$/u', $name)) {
            $io->error("Invalid MySQL identifier for db_name: {$name}");
            return Command::FAILURE;
        }

        return null;
    }

    /**
     * `empty()` would false-positive on a password literally equal to "0"
     * and raise a notice on absent keys — check string-presence directly.
     *
     * @param array<string, mixed> $config
     */
    private function validateMysqlConfig(SymfonyStyle $io, array $config): ?int
    {
        $missing = [];
        foreach (['db_host' => 'SPORA_DB_HOST', 'db_user' => 'SPORA_DB_USER', 'db_password' => 'SPORA_DB_PASSWORD'] as $key => $env) {
            $value = $config[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $missing[] = $env;
            }
        }
        if ($missing) {
            $io->error('Cannot reset MySQL: missing config: ' . implode(', ', $missing));
            $io->writeln('Set these in <comment>.env</comment> (or <comment>config.php</comment>) and re-run.');
            return Command::FAILURE;
        }

        return null;
    }

    private function confirmMysqlResetPrecondition(SymfonyStyle $io, bool $force, string $name, ?string $host, int $port): ?int
    {
        if (!$force && !$this->confirmMysqlReset($io, $name, $host, $port)) {
            return Command::FAILURE;
        }

        return null;
    }

    private function confirmMysqlReset(SymfonyStyle $io, string $name, ?string $host, int $port): bool
    {
        $io->writeln("About to <error>DROP DATABASE</error> `{$name}` on {$host}:{$port} and recreate it empty.");
        $io->writeln('This is irreversible.');
        // ask() returns the default ('') in non-interactive mode, so non-TTY
        // runs already fail closed at the `!== 'yes'` check below.
        $answer = $io->ask('Type "yes" to continue, anything else to abort', '');
        if ($answer === 'yes') {
            return true;
        }
        $io->writeln('Aborted. No changes made. (Pass --force to skip the prompt in non-interactive runs.)');
        return false;
    }
}
