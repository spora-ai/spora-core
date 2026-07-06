<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Closure;
use FilesystemIterator;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Console\Commands\MediaArchiveListCommand;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Coverage for the `media:list` CLI command. Boots an in-memory service
 * the same way {@see \Tests\Feature\MediaArchive\MediaArchiveApiTest} does
 * and drives the command via {@see CommandTester}.
 *
 * The fixture routes asset writes through `SPORA_STORAGE_DIR` so the service
 * uses an isolated tmp dir. Tests must restore the env var in a `finally`
 * block — leaving it set leaks into other suites (PathsTest,
 * SeedCommandTest, ContainerDefinitionsTest, ContainerConfigTest,
 * PluginCatalogServiceTest, CoreExceptionsTest) which read it back via
 * `Paths::storage()`. The shared {@see withMediaListStorageDir()} helper
 * handles the save/restore dance so individual tests stay focused on
 * assertions.
 */
function withMediaListStorageDir(string $tmp): Closure
{
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    return static function () use ($previous): void {
        if ($previous === false) {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        } else {
            putenv("SPORA_STORAGE_DIR={$previous}");
            $_ENV['SPORA_STORAGE_DIR']    = $previous;
            $_SERVER['SPORA_STORAGE_DIR'] = $previous;
        }
    };
}

function makeListCommandService(string $tmp): MediaArchiveService
{
    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();
    $fetcher   = new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 100 * 1024 * 1024);

    return new MediaArchiveService(
        $assetStore,
        $fetcher,
        $sniffer,
        $metadata,
        $logger,
        true,
        100 * 1024 * 1024,
    );
}

/**
 * Build a CommandTester for an empty in-memory service.
 *
 * @return array{0: CommandTester, 1: Closure, 2: string}
 */
function makeListCommandTester(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    $restore = withMediaListStorageDir($tmp);

    $service = makeListCommandService($tmp);

    $command = new MediaArchiveListCommand($service);
    $command->setName('media:list');

    return [new CommandTester($command), $restore, $tmp];
}

const MEDIA_LIST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

afterEach(function (): void {
    // Defensive: tidy tmp dirs created by this file in case a test crashed
    // before its finally block ran.
    foreach (glob(sys_get_temp_dir() . '/spora-media-list-cmd-*') ?: [] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
});

it('prints a friendly empty-state when no rows match', function (): void {
    [$tester, $restore] = makeListCommandTester();
    try {
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('No archived media matched the filters.');
    } finally {
        $restore();
    }
});

it('renders a table of rows when there are matches', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    $restore = withMediaListStorageDir($tmp);

    try {
        $service = makeListCommandService($tmp);

        // Insert one row.
        $png = base64_decode(MEDIA_LIST_PNG, strict: true);
        $service->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

        $command = new MediaArchiveListCommand($service);
        $command->setName('media:list');
        $tester = new CommandTester($command);

        $tester->execute([]);
        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        $display = $tester->getDisplay();
        expect($display)->toContain('image');
        expect($display)->toContain('image/png');
        expect($display)->toContain('1 total assets');
    } finally {
        $restore();
    }
});

it('emits a JSON envelope when --json is passed', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    $restore = withMediaListStorageDir($tmp);

    try {
        $service = makeListCommandService($tmp);

        $png = base64_decode(MEDIA_LIST_PNG, strict: true);
        $service->ingest(new MediaIngestRequest(bytes: $png, mime: 'image/png'));

        $command = new MediaArchiveListCommand($service);
        $command->setName('media:list');
        $tester = new CommandTester($command);

        $tester->execute(['--json' => true]);
        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        $payload = json_decode($tester->getDisplay(), true);
        expect($payload)->toBeArray();
        expect($payload['data']['total'])->toBe(1);
        expect($payload['data']['assets'][0]['mime_type'])->toBe('image/png');
    } finally {
        $restore();
    }
});

it('rejects an unknown --type with FAILURE and an error message', function (): void {
    [$tester, $restore] = makeListCommandTester();
    try {
        $tester->execute(['--type' => 'bogus']);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())->toContain('Unknown media type');
    } finally {
        $restore();
    }
});

it('rejects an unparseable --since with FAILURE', function (): void {
    [$tester, $restore] = makeListCommandTester();
    try {
        $tester->execute(['--since' => 'not a date string']);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())->toContain('Could not parse --since');
    } finally {
        $restore();
    }
});

it('accepts a valid --type and --since combination', function (): void {
    [$tester, $restore] = makeListCommandTester();
    try {
        $tester->execute(['--type' => 'image', '--since' => '2025-01-01']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    } finally {
        $restore();
    }
});

it('silently drops filters with empty values', function (): void {
    [$tester, $restore] = makeListCommandTester();
    try {
        // Empty type / search / agent / plugin / tool / since are all
        // legitimate "no filter" inputs and should not fail the command.
        $tester->execute([
            '--type'    => '',
            '--search'  => '',
            '--agent'   => '',
            '--plugin'  => '',
            '--tool'    => '',
            '--since'   => '',
        ]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    } finally {
        $restore();
    }
});
