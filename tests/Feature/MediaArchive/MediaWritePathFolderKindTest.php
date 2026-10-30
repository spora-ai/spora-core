<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaAssetKind;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;

/**
 * The media write path accepts `folder_id` and `kind`.
 *
 * The folder is where the asset lives and the kind is an advisory mark;
 * neither is a gate — the kind in particular never refuses an asset.
 */
function writePathService(): array
{
    $tmp = sys_get_temp_dir() . '/spora-write-path-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $derivatives = MediaArchiveTestSupport::buildDerivativeService($assetStore);
    $service = MediaArchiveTestSupport::buildService($assetStore, derivatives: $derivatives);
    $auth = bootAuthLayer();

    $allowed = new MediaAllowedTypesService($derivatives, new \Spora\Drivers\DriverFactory(
        new \Psr\Log\NullLogger(),
        new \Spora\Services\LLMConfigService($security, []),
        300,
    ));

    $resolver = new PrincipalResolver();
    $controller = new \Spora\Http\MediaUploadController(
        $service,
        new \Spora\Services\MediaArchive\MediaArchiveRetention(),
        $allowed,
        $auth,
        $resolver,
        new MimeSniffer(),
        new Config(),
        new \Spora\Services\MediaArchive\MediaAssetSerializer(),
    );

    return [$service, $controller, $auth];
}

function writePathUploadRequest(string $filename, string $contents, array $fields = []): Request
{
    $tmp = tempnam(sys_get_temp_dir(), 'wp');
    file_put_contents($tmp, $contents);

    return Request::create('/api/v1/media', 'POST', $fields, files: [
        'file' => new UploadedFile($tmp, $filename, 'text/plain', null, true),
    ]);
}

describe('the ingest pipeline', function (): void {
    it('files the asset in the folder and marks it with the kind', function (): void {
        $principalId = createUserPrincipalPublic(621);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        [$service] = writePathService();

        $asset = $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
            kind: MediaAssetKind::TEMPLATE,
        ));

        expect((string) $asset->folder_id)->toBe((string) $folder->id);
        expect((string) $asset->kind)->toBe(MediaAssetKind::TEMPLATE);
    });

    it('leaves the asset in the root with the default kind', function (): void {
        $principalId = createUserPrincipalPublic(622);
        [$service] = writePathService();

        $asset = $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
        ));

        expect($asset->folder_id)->toBeNull();
        expect((string) $asset->kind)->toBe(MediaAssetKind::ASSET);
    });

    it('stores an advisory kind outside the known vocabulary as given', function (): void {
        // The service stores what it is handed; refusing a value outside
        // the vocabulary belongs to the HTTP layer, which answers 400
        // before the bytes reach the asset store.
        $principalId = createUserPrincipalPublic(623);
        [$service] = writePathService();

        $asset = $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            kind: 'house-style',
        ));

        expect((string) $asset->kind)->toBe('house-style');
    });

    it('refuses a filename the folder already holds with the domain error', function (): void {
        // The rule is "one filename per folder". The ingest writes
        // through the folder service, so the collision is a domain error
        // the caller can answer with a 422 — not the raw unique-index
        // violation that would surface as a 500.
        $principalId = createUserPrincipalPublic(628);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        [$service] = writePathService();

        $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        ));

        expect(fn() => $service->ingest(new MediaIngestRequest(
            bytes: '# hello again',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        )))->toThrow(MediaFolderUniquenessException::class);
    });

    it('refuses a folder that is not the asset\'s principal', function (): void {
        $ownerId = createUserPrincipalPublic(629);
        $foreignFolder = (new MediaFolderService())->create($ownerId, 'Corporate');
        [$service] = writePathService();

        expect(fn() => $service->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: createUserPrincipalPublic(630),
            folderId: (string) $foreignFolder->id,
        )))->toThrow(MediaFolderNotFoundException::class);
    });
});

describe('the upload endpoint', function (): void {
    it('files the upload in the folder and marks it with the kind', function (): void {
        [$service, $controller, $auth] = writePathService();
        $userId = bootAuth($auth, 'wp-upload@example.com', 'Password1!');
        $principalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');

        $request = writePathUploadRequest('theme.md', '# hello', [
            'principal_id' => (string) $principalId,
            'folder_id'    => (string) $folder->id,
            'kind'         => MediaAssetKind::TEMPLATE,
        ]);

        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode((string) $response->getContent(), true);
        $asset = $service->find($body['data']['id']);
        expect((string) $asset?->folder_id)->toBe((string) $folder->id);
        expect((string) $asset?->kind)->toBe(MediaAssetKind::TEMPLATE);
    });

    it('answers 422 when the folder already holds a file with that name', function (): void {
        [$archive, $controller, $auth] = writePathService();
        $userId = bootAuth($auth, 'wp-dup@example.com', 'Password1!');
        $principalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');

        $archive->ingest(new MediaIngestRequest(
            bytes: '# hello',
            mime: 'text/markdown',
            filename: 'theme.md',
            principalId: $principalId,
            folderId: (string) $folder->id,
        ));

        $request = writePathUploadRequest('theme.md', '# hello again', [
            'principal_id' => (string) $principalId,
            'folder_id'    => (string) $folder->id,
        ]);

        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['error']['code'])->toBe('FOLDER_FILENAME_TAKEN');
        expect($body['error']['message'])->toContain('theme.md');
    });

    it('answers 422 for a folder of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(626);
        [, $controller, $auth] = writePathService();
        $userId = bootAuth($auth, 'wp-foreign@example.com', 'Password1!');
        $principalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
        $foreignFolder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $request = writePathUploadRequest('theme.md', '# hello', [
            'principal_id' => (string) $principalId,
            'folder_id'    => (string) $foreignFolder->id,
        ]);

        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    it('answers 400 for a kind outside the vocabulary and stores nothing', function (): void {
        // A kind the column cannot hold and the filter cannot select is
        // refused before the bytes reach the asset store — otherwise the
        // row is written and the save trips the column's length check,
        // which answers a 500 and leaves an orphaned blob behind.
        [$service, $controller, $auth] = writePathService();
        $userId = bootAuth($auth, 'wp-bad-kind@example.com', 'Password1!');
        $principalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;

        $request = writePathUploadRequest('theme.md', '# hello', [
            'principal_id' => (string) $principalId,
            'kind'         => 'a-kind-that-is-far-too-long',
        ]);

        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['error']['code'])->toBe('INVALID_KIND');
        expect($service->list(new ListMediaQuery(principalIds: [$principalId]))->total())->toBe(0);
    });

    it('stores a kind inside the vocabulary', function (): void {
        [$service, $controller, $auth] = writePathService();
        $userId = bootAuth($auth, 'wp-good-kind@example.com', 'Password1!');
        $principalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;

        $request = writePathUploadRequest('theme.md', '# hello', [
            'principal_id' => (string) $principalId,
            'kind'         => MediaAssetKind::EXAMPLE,
        ]);

        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['data']['kind'])->toBe(MediaAssetKind::EXAMPLE);
    });
});
