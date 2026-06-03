<?php

declare(strict_types=1);

use Spora\Console\Commands\DbResetCommand;
use Spora\Core\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Helpers
 *
 * DbResetCommand touches the filesystem, so each test gets a fresh temp
 * directory and a real (not :memory:) SQLite file. The temp dir is auto-
 * cleaned by the OS once the test exits, no manual teardown needed.
 *
 * The schema-stamp path is read from the *real* BASE_PATH/./storage/.schema_stamp
 * (the command's behaviour matches the rest of Spora). Tests that exercise the
 * stamp save/restore the file around the test run.
 */

function makeTempSqliteFile(string $contents = ''): string
{
    $dir = sys_get_temp_dir() . '/spora-db-reset-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    $path = $dir . '/storage/database.sqlite';
    @mkdir(dirname($path), 0o755, true);

    if ($contents !== '') {
        file_put_contents($path, $contents);
    } else {
        touch($path);
    }

    return $path;
}

function makeConfigFor(string $dbPath, string $driver = 'sqlite'): array
{
    return [
        'db_driver'   => $driver,
        'db_path'     => $dbPath,
        'db_host'     => null,
        'db_port'     => null,
        'db_name'     => null,
        'db_user'     => null,
        'db_password' => null,
    ];
}

function makeDbFor(string $dbPath, string $driver = 'sqlite'): Database
{
    Database::resetBootState();
    return new Database(makeConfigFor($dbPath, $driver));
}

function makeTester(Database $db): CommandTester
{
    Database::resetBootState();
    $command = new DbResetCommand($db);
    $command->setName('db:reset');
    return new CommandTester($command);
}

function withSchemaStamp(callable $fn): void
{
    $stamp = BASE_PATH . '/storage/.schema_stamp';
    $backup = null;
    if (file_exists($stamp)) {
        $backup = file_get_contents($stamp);
        unlink($stamp);
    }
    try {
        $fn();
    } finally {
        if ($backup !== null) {
            file_put_contents($stamp, $backup);
        }
    }
}

// Tests

test('--force wipes a non-empty SQLite file and clears the schema stamp', function (): void {
    withSchemaStamp(function (): void {
        $stamp = BASE_PATH . '/storage/.schema_stamp';
        file_put_contents($stamp, 'stale-hash');

        $dbPath = makeTempSqliteFile('not really a sqlite file, just non-empty');
        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        $tester->execute(['--force' => true]);

        expect($tester->getStatusCode())->toBe(0);
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBe(0);
        expect(file_exists($stamp))->toBeFalse();
    });
});

test('--force on a missing SQLite file creates an empty one', function (): void {
    withSchemaStamp(function (): void {
        $dir = sys_get_temp_dir() . '/spora-db-reset-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $dbPath = $dir . '/storage/database.sqlite';

        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        expect(file_exists($dbPath))->toBeFalse();

        $tester->execute(['--force' => true]);

        expect($tester->getStatusCode())->toBe(0);
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBe(0);
    });
});

test('non-interactive stdin on a non-empty file aborts without --force', function (): void {
    withSchemaStamp(function (): void {
        $dbPath = makeTempSqliteFile('user data worth protecting');
        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        // interactive=false makes confirm() return its default (false) immediately.
        $tester->execute([], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBeGreaterThan(0);
        expect($tester->getDisplay())
            ->toContain('Aborted')
            ->toContain('--force');
    });
});

test('--force is the documented escape hatch and always wins', function (): void {
    withSchemaStamp(function (): void {
        $dbPath = makeTempSqliteFile('preserved unless --force is given');
        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBe(0);
    });
});

test('help text documents both SQLite and MySQL paths', function (): void {
    $db = makeDbFor(makeTempSqliteFile());
    $command = new DbResetCommand($db);
    $command->setName('db:reset');

    $help = $command->getHelp();

    expect($help)->toContain('SQLite');
    expect($help)->toContain('MySQL');
    expect($help)->toContain('--force');
});

test('MySQL branch with missing config fails with a clear error', function (): void {
    withSchemaStamp(function (): void {
        // No db_host / db_name / db_user / db_password — all null.
        $db = new Database(['db_driver' => 'mysql']);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())
            ->toContain('SPORA_DB_HOST')
            ->toContain('SPORA_DB_NAME')
            ->toContain('SPORA_DB_USER')
            ->toContain('SPORA_DB_PASSWORD');
    });
});

test('MySQL branch with full config DROPs and CREATEs the database via PDO', function (): void {
    withSchemaStamp(function (): void {
        $config = [
            'db_driver'   => 'mysql',
            'db_host'     => 'db.example.com',
            'db_port'     => 3306,
            'db_name'     => 'spora_test',
            'db_user'     => 'root',
            'db_password' => 'secret',
        ];
        $db = new Database($config);

        $executedSql = [];
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')
            ->andReturnUsing(function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;
                return 0;
            });

        $command = new DbResetCommand(
            $db,
            static fn(string $dsn, string $user, string $password, array $options) => $pdo,
        );
        $command->setName('db:reset');
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($executedSql)->toHaveCount(2);
        expect($executedSql[0])->toBe('DROP DATABASE IF EXISTS `spora_test`');
        expect($executedSql[1])->toBe('CREATE DATABASE `spora_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    });
});

test('MySQL branch defaults db_port to 3306 when not configured', function (): void {
    withSchemaStamp(function (): void {
        $config = [
            'db_driver'   => 'mysql',
            'db_host'     => 'db.example.com',
            // db_port intentionally omitted.
            'db_name'     => 'spora_test',
            'db_user'     => 'root',
            'db_password' => 'secret',
        ];
        $db = new Database($config);

        $capturedDsn = null;
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->andReturn(0);
        $command = new DbResetCommand(
            $db,
            static function (string $dsn, string $user, string $password, array $options) use (&$capturedDsn, $pdo): PDO {
                $capturedDsn = $dsn;
                return $pdo;
            },
        );
        $command->setName('db:reset');
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($capturedDsn)->toBe('mysql:host=db.example.com;port=3306;charset=utf8mb4');
    });
});

test('MySQL branch surfaces PDO exceptions as a clear error', function (): void {
    withSchemaStamp(function (): void {
        $config = [
            'db_driver'   => 'mysql',
            'db_host'     => 'db.example.com',
            'db_name'     => 'spora_test',
            'db_user'     => 'root',
            'db_password' => 'secret',
        ];
        $db = new Database($config);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->andThrow(new RuntimeException('Access denied for user'));

        $command = new DbResetCommand(
            $db,
            static fn() => $pdo,
        );
        $command->setName('db:reset');
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())
            ->toContain('Reset failed')
            ->toContain('Access denied for user');
    });
});

test('MySQL branch with partial config lists every missing env var', function (): void {
    withSchemaStamp(function (): void {
        $config = [
            'db_driver'   => 'mysql',
            'db_host'     => 'db.example.com',
            // db_name, db_user, db_password all missing.
        ];
        $db = new Database($config);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        $display = $tester->getDisplay();
        // All three missing env vars must be listed; Spora_DB_HOST was provided
        // so it should NOT appear in the "missing" error.
        expect($display)->toContain('SPORA_DB_NAME');
        expect($display)->toContain('SPORA_DB_USER');
        expect($display)->toContain('SPORA_DB_PASSWORD');
        expect($display)->not->toContain('SPORA_DB_HOST');
    });
});

test('SQLite branch respects a custom db_path from config', function (): void {
    withSchemaStamp(function (): void {
        // db_path points at a non-default location.
        $custom = sys_get_temp_dir() . '/spora-custom-' . bin2hex(random_bytes(6)) . '/data/spora.sqlite';
        @mkdir(dirname($custom), 0o755, true);
        file_put_contents($custom, 'existing data');

        $db = new Database([
            'db_driver' => 'sqlite',
            'db_path'   => $custom,
        ]);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(file_exists($custom))->toBeTrue();
        expect(filesize($custom))->toBe(0);
    });
});

test('SQLite branch creates the parent directory if it does not exist', function (): void {
    withSchemaStamp(function (): void {
        $dir = sys_get_temp_dir() . '/spora-nested-' . bin2hex(random_bytes(6)) . '/a/b/c';
        $dbPath = $dir . '/db.sqlite';

        // Pre-condition: nested path does not exist.
        expect(is_dir($dir))->toBeFalse();
        expect(file_exists($dbPath))->toBeFalse();

        $db = new Database([
            'db_driver' => 'sqlite',
            'db_path'   => $dbPath,
        ]);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(is_dir($dir))->toBeTrue();
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBe(0);
    });
});

test('empty (0-byte) SQLite file is wiped without a prompt', function (): void {
    withSchemaStamp(function (): void {
        $dbPath = makeTempSqliteFile(''); // empty 0-byte file
        expect(filesize($dbPath))->toBe(0);

        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        $tester->execute([], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(file_exists($dbPath))->toBeTrue();
        expect(filesize($dbPath))->toBe(0); // still empty
    });
});

test('non-SQLite, non-MySQL driver falls through to the SQLite branch', function (): void {
    withSchemaStamp(function (): void {
        // A typo / future driver should not blow up — it should be treated as SQLite.
        $dbPath = makeTempSqliteFile('non-empty');
        $db = new Database([
            'db_driver' => 'postgres', // unrecognised
            'db_path'   => $dbPath,
        ]);
        $tester = makeTester($db);

        // No --force and the file is non-empty — would prompt. With interactive=false
        // the prompt auto-aborts, so we go through --force to confirm the path is taken.
        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(filesize($dbPath))->toBe(0);
    });
});

test('case-insensitive driver match for mysql', function (): void {
    withSchemaStamp(function (): void {
        $config = [
            'db_driver'   => 'MySQL', // mixed case
            'db_host'     => 'db.example.com',
            'db_port'     => 3307,
            'db_name'     => 'spora_test',
            'db_user'     => 'root',
            'db_password' => 'secret',
        ];
        $db = new Database($config);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->andReturn(0);
        $command = new DbResetCommand(
            $db,
            static fn() => $pdo,
        );
        $command->setName('db:reset');
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    });
});

test('--force short alias -f works', function (): void {
    withSchemaStamp(function (): void {
        $dbPath = makeTempSqliteFile('preserved unless -f is given');
        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        $tester->execute(['-f' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(filesize($dbPath))->toBe(0);
    });
});

test('schema stamp is left alone if it does not exist (no error)', function (): void {
    withSchemaStamp(function (): void {
        $dbPath = makeTempSqliteFile('');
        $db = makeDbFor($dbPath);
        $tester = makeTester($db);

        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        // Stamp never existed, command should not error.
        expect(file_exists(BASE_PATH . '/storage/.schema_stamp'))->toBeFalse();
    });
});
