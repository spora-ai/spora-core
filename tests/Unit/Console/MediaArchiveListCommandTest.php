<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Psr\Log\NullLogger;
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
 */
function makeListCommandTester(): CommandTester
{
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();
    $fetcher   = new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 100 * 1024 * 1024);

    $service = new MediaArchiveService(
        $assetStore,
        $fetcher,
        $sniffer,
        $metadata,
        $logger,
        true,
        100 * 1024 * 1024,
    );

    $command = new MediaArchiveListCommand($service);
    $command->setName('media:list');

    return $tester = new CommandTester($command);
}

const MEDIA_LIST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

it('prints a friendly empty-state when no rows match', function (): void {
    $tester = makeListCommandTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('No archived media matched the filters.');
});

it('renders a table of rows when there are matches', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();
    $fetcher   = new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 100 * 1024 * 1024);

    $service = new MediaArchiveService(
        $assetStore,
        $fetcher,
        $sniffer,
        $metadata,
        $logger,
        true,
        100 * 1024 * 1024,
    );

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

    // Cleanup
    @unlink($tmp);
});

it('emits a JSON envelope when --json is passed', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-media-list-cmd-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();
    $fetcher   = new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 100 * 1024 * 1024);

    $service = new MediaArchiveService(
        $assetStore,
        $fetcher,
        $sniffer,
        $metadata,
        $logger,
        true,
        100 * 1024 * 1024,
    );

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

    // Cleanup
    @unlink($tmp);
});

it('rejects an unknown --type with FAILURE and an error message', function (): void {
    $tester = makeListCommandTester();
    $tester->execute(['--type' => 'bogus']);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('Unknown media type');
});

it('rejects an unparseable --since with FAILURE', function (): void {
    $tester = makeListCommandTester();
    $tester->execute(['--since' => 'not a date string']);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())->toContain('Could not parse --since');
});

it('accepts a valid --type and --since combination', function (): void {
    $tester = makeListCommandTester();
    $tester->execute(['--type' => 'image', '--since' => '2025-01-01']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
});

it('silently drops filters with empty values', function (): void {
    $tester = makeListCommandTester();
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
});
