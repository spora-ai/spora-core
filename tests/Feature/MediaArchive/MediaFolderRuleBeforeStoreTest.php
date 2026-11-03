<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MediaArchiveTestSupport;

/**
 * The folder rule is checked before the bytes reach the AssetStore.
 *
 * The blob lands before the row, so a filing the rule refuses would
 * leave a blob no row points at — and `AssetStore` has no `delete()` to
 * lean on, so nothing sweeps it. The assertion is therefore that the
 * store was never written to.
 */
function localStoreArchive(string $storageDir): MediaArchiveService
{
    putenv("SPORA_STORAGE_DIR={$storageDir}");
    $_ENV['SPORA_STORAGE_DIR']    = $storageDir;
    $_SERVER['SPORA_STORAGE_DIR'] = $storageDir;

    return MediaArchiveTestSupport::buildService(new LocalAssetStore(
        new Paths(BASE_PATH),
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    ));
}

function folderRuleTmpDir(): string
{
    $dir = sys_get_temp_dir() . '/spora-folder-rule-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, recursive: true);
    $GLOBALS['__spora_folder_rule_tmp_dirs'][] = $dir;

    return $dir;
}

afterEach(function (): void {
    foreach ($GLOBALS['__spora_folder_rule_tmp_dirs'] ?? [] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
    $GLOBALS['__spora_folder_rule_tmp_dirs'] = [];
});

/**
 * The files the store wrote.
 *
 * @return list<string>
 */
function storedAssetFiles(string $storageDir): array
{
    $dir = $storageDir . '/assets';
    if (!is_dir($dir)) {
        return [];
    }

    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

/**
 * A row filed by hand.
 *
 * The store is not involved: the row exists so that the rule has
 * something to refuse, without a blob of its own.
 */
function filedAssetRow(int $principalId, string $folderId, string $filename): MediaAsset
{
    $id = testGenerateUuidV4();

    $asset = new MediaAsset();
    $asset->id = $id;
    $asset->principal_id = $principalId;
    $asset->folder_id = $folderId;
    $asset->filename = $filename;
    $asset->media_type = 'document';
    $asset->mime_type = 'text/markdown';
    $asset->storage_mode = 'data_url';
    $asset->asset_url = '/api/v1/assets/' . $id . '.md';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->save();

    return $asset;
}

/**
 * A tool call the ingest can be keyed on.
 *
 * The dedup key is `(tool_call_id, source_url)`, so the re-ingest needs a
 * row to hang on.
 */
function toolCallForPrincipal(int $principalId, int $userId): ToolCall
{
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'folder-rule-agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'agent_id'        => $agent->id,
        'status'          => 'RUNNING',
        'user_prompt'     => 'folder rule',
        'max_steps'       => 10,
    ]);

    return ToolCall::create([
        'task_id'              => $task->id,
        'agent_id'             => $agent->id,
        'provider_call_id'     => 'call_folder_rule',
        'tool_name'            => 'folder_rule_tool',
        'tool_class'           => 'FolderRuleTool',
        'tool_type'            => 'function',
        'operation'            => 'do',
        'operation_description' => 'do',
        'proposed_arguments'   => ['x' => 1],
        'status'               => 'EXECUTED',
    ]);
}

/**
 * An archive whose source fetch is the window a concurrent writer lands in.
 *
 * The row a re-ingest refreshes has to appear between the pipeline's
 * lookup and its write; the fetch is the only place that window opens,
 * so the concurrent writer is simulated there.
 *
 * @return array{0: MediaArchiveService, 1: Closure(): ?MediaAsset} the archive and the concurrent row
 */
function archiveWithConcurrentWriter(
    int $principalId,
    ToolCall $toolCall,
    string $folderId,
    string $sourceUrl,
    string $filename,
): array {
    $concurrent = null;

    $storageDir = folderRuleTmpDir();
    putenv("SPORA_STORAGE_DIR={$storageDir}");
    $_ENV['SPORA_STORAGE_DIR']    = $storageDir;
    $_SERVER['SPORA_STORAGE_DIR'] = $storageDir;

    $http = new MockHttpClient(function () use (&$concurrent, $principalId, $toolCall, $folderId, $sourceUrl, $filename): MockResponse {
        if ($concurrent === null) {
            $concurrent = filedAssetRow($principalId, $folderId, $filename);
            $concurrent->tool_call_id = $toolCall->id;
            $concurrent->source_url = $sourceUrl;
            $concurrent->save();
        }

        return new MockResponse('# hello', ['response_headers' => ['content-type' => 'text/markdown']]);
    });

    $service = MediaArchiveTestSupport::buildService(
        new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        http: $http,
    );

    return [$service, function () use (&$concurrent): ?MediaAsset {
        return $concurrent;
    }];
}

describe('the folder rule is checked before the bytes are stored', function (): void {
    it('stores nothing when the folder already holds the filename', function (): void {
        $principalId = createUserPrincipalPublic(641);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        filedAssetRow($principalId, (string) $folder->id, 'theme.md');

        $storageDir = folderRuleTmpDir();
        $service = localStoreArchive($storageDir);

        expect(fn() => $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        )))->toThrow(MediaFolderUniquenessException::class);

        expect(storedAssetFiles($storageDir))->toBe([]);
    });

    it('stores nothing when the folder is not the principal\'s', function (): void {
        $ownerId = createUserPrincipalPublic(642);
        $foreignFolder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $storageDir = folderRuleTmpDir();
        $service = localStoreArchive($storageDir);

        expect(fn() => $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: createUserPrincipalPublic(643),
            folderId: (string) $foreignFolder->id,
        )))->toThrow(MediaFolderNotFoundException::class);

        expect(storedAssetFiles($storageDir))->toBe([]);
    });
});

describe('an idempotent re-ingest', function (): void {
    it('accepts the asset that already sits in the folder it names', function (): void {
        // The re-ingest refreshes the row instead of inserting one, and
        // the row keeps its own folder — so the rule is checked against
        // that folder, with the row's own id excluded. Without that
        // exclusion the row collides with itself and the re-ingest is
        // refused for a name it already holds.
        $userId = bootAuthLayer()->register('reingest@example.com', 'Password1!', 'Reingest');
        $principalId = createUserPrincipalPublic($userId);
        $toolCall = toolCallForPrincipal($principalId, $userId);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');

        [$archive, $concurrentRow] = archiveWithConcurrentWriter(
            $principalId,
            $toolCall,
            (string) $folder->id,
            'https://cdn.example/reingest.md',
            'theme.md',
        );

        $asset = $archive->ingest(new MediaIngestRequest(
            url: 'https://cdn.example/reingest.md',
            toolCallId: $toolCall->id,
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        ));

        expect((string) $asset->id)->toBe((string) $concurrentRow()?->id);
        expect((string) $asset->fresh()?->folder_id)->toBe((string) $folder->id);
        expect((string) $asset->fresh()?->filename)->toBe('theme.md');
        expect(MediaAsset::query()->where('folder_id', $folder->id)->count())->toBe(1);
    });

    it('refuses a new filename that collides with another asset in the folder', function (): void {
        // The domain error, not the raw unique-index violation: the rule
        // is checked before the row is written, so the caller gets the
        // 422 the rule promises instead of a 500.
        $userId = bootAuthLayer()->register('reingest-collide@example.com', 'Password1!', 'ReingestCollide');
        $principalId = createUserPrincipalPublic($userId);
        $toolCall = toolCallForPrincipal($principalId, $userId);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        filedAssetRow($principalId, (string) $folder->id, 'taken.md');

        [$archive] = archiveWithConcurrentWriter(
            $principalId,
            $toolCall,
            (string) $folder->id,
            'https://cdn.example/reingest-collide.md',
            'theme.md',
        );

        expect(fn() => $archive->ingest(new MediaIngestRequest(
            url: 'https://cdn.example/reingest-collide.md',
            toolCallId: $toolCall->id,
            filename: 'taken.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        )))->toThrow(MediaFolderUniquenessException::class);
    });

    it('refuses a colliding filename in the row\'s own folder when the request names no folder', function (): void {
        // A refresh does not move the row — the request's `folder_id`
        // does not move an existing row — so the filename lands in the
        // folder the row already carries. The rule is judged against that
        // folder, or the refresh would land on the raw unique index and
        // answer a 500 where the rule says 422.
        $userId = bootAuthLayer()->register('reingest-nofolder@example.com', 'Password1!', 'ReingestNoFolder');
        $principalId = createUserPrincipalPublic($userId);
        $toolCall = toolCallForPrincipal($principalId, $userId);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        filedAssetRow($principalId, (string) $folder->id, 'taken.md');

        [$archive] = archiveWithConcurrentWriter(
            $principalId,
            $toolCall,
            (string) $folder->id,
            'https://cdn.example/reingest-nofolder.md',
            'theme.md',
        );

        expect(fn() => $archive->ingest(new MediaIngestRequest(
            url: 'https://cdn.example/reingest-nofolder.md',
            toolCallId: $toolCall->id,
            filename: 'taken.md',
            principalId: $principalId,
        )))->toThrow(MediaFolderUniquenessException::class);
    });
});
