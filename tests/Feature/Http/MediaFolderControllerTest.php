<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\RouteDefinitions;
use Spora\Http\MediaFolderController;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Models\MediaFolder;
use Spora\OpenApi\RouteSpecCollector;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

defined('MEDIA_FOLDER_PASSWORD') || define('MEDIA_FOLDER_PASSWORD', 'Password1!');

/**
 * @return array{0: MediaFolderController, 1: \Spora\Auth\AuthService, 2: PrincipalService}
 */
function buildFolderController(): array
{
    $resolver = new PrincipalResolver();
    $service = new PrincipalService($resolver);
    $auth = bootAuthLayer();

    return [
        new MediaFolderController(new MediaFolderService(), $auth, $resolver, $service),
        $auth,
        $service,
    ];
}

function folderRequest(string $method, string $uri, array $body = []): Request
{
    $content = $body === [] ? '' : (string) json_encode($body);

    return Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * @return list<array<string, mixed>>
 */
function mediaFolderRouteRows(): array
{
    $collector = new RouteSpecCollector();
    RouteDefinitions::register($collector);

    return array_values(array_filter(
        $collector->routes(),
        static fn(array $row): bool => str_starts_with((string) $row['route'], '/api/v1/media/folders'),
    ));
}

beforeEach(function (): void {
    clearSession();
});

afterEach(function (): void {
    clearSession();
});

describe('the folders routes', function (): void {
    it('registers the four folder routes behind auth and CSRF', function (): void {
        $rows = mediaFolderRouteRows();

        expect($rows)->toHaveCount(4);

        foreach ($rows as $row) {
            expect($row['middleware'])->toBe([AuthMiddleware::class, CsrfMiddleware::class]);
        }

        $paths = array_map(static fn(array $row): string => $row['method'] . ' ' . $row['route'], $rows);
        expect($paths)->toContain('GET /api/v1/media/folders');
        expect($paths)->toContain('POST /api/v1/media/folders');
        expect($paths)->toContain('PATCH /api/v1/media/folders/{id}');
        expect($paths)->toContain('DELETE /api/v1/media/folders/{id}');
    });
});

describe('GET /api/v1/media/folders', function (): void {
    it('lists the caller\'s folders', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-list@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        (new MediaFolderService())->create($principalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-list@example.com');
        $response = $controller->index(folderRequest('GET', '/api/v1/media/folders'));

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['data']['folders'])->toHaveCount(1);
        expect($body['data']['folders'][0]['name'])->toBe('Corporate');
        expect($body['data']['folders'][0]['path'])->toBe('/Corporate');
    });

    it('answers 404 for a principal the caller cannot act for', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-scope@example.com', MEDIA_FOLDER_PASSWORD);
        $otherId = bootAuth($auth, 'mf-other@example.com', MEDIA_FOLDER_PASSWORD);
        $foreignPrincipalId = $principalService->ensureUserPrincipal($otherId)->id;

        simulateLoggedInSession($callerId, 'mf-scope@example.com');
        $request = folderRequest('GET', '/api/v1/media/folders?principal_id=' . $foreignPrincipalId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(404);
    });
});

describe('POST /api/v1/media/folders', function (): void {
    it('creates a folder and answers 201', function (): void {
        [$controller, $auth] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-create@example.com', MEDIA_FOLDER_PASSWORD);

        simulateLoggedInSession($callerId, 'mf-create@example.com');
        $response = $controller->store(folderRequest('POST', '/api/v1/media/folders', ['name' => 'Corporate']));

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['data']['name'])->toBe('Corporate');
        expect($body['data']['path'])->toBe('/Corporate');
        expect(MediaFolder::query()->where('path', '/Corporate')->count())->toBe(1);
    });

    it('answers 422 for a duplicate sibling name', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-dup@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        (new MediaFolderService())->create($principalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-dup@example.com');
        $response = $controller->store(folderRequest('POST', '/api/v1/media/folders', ['name' => 'Corporate']));

        expect($response->getStatusCode())->toBe(422);
    });

    it('answers 400 for a name carrying a path separator', function (): void {
        [$controller, $auth] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-slash@example.com', MEDIA_FOLDER_PASSWORD);

        simulateLoggedInSession($callerId, 'mf-slash@example.com');
        $response = $controller->store(folderRequest('POST', '/api/v1/media/folders', ['name' => 'a/b']));

        expect($response->getStatusCode())->toBe(400);
    });

    it('answers 400 when the name is missing', function (): void {
        [$controller, $auth] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-noname@example.com', MEDIA_FOLDER_PASSWORD);

        simulateLoggedInSession($callerId, 'mf-noname@example.com');
        $response = $controller->store(folderRequest('POST', '/api/v1/media/folders', []));

        expect($response->getStatusCode())->toBe(400);
    });
});

describe('PATCH /api/v1/media/folders/{id}', function (): void {
    it('renames a folder', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-rename@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        $folder = (new MediaFolderService())->create($principalId, 'Old');

        simulateLoggedInSession($callerId, 'mf-rename@example.com');
        $response = $controller->update(
            (string) $folder->id,
            folderRequest('PATCH', '/api/v1/media/folders/' . $folder->id, ['name' => 'New']),
        );

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['data']['path'])->toBe('/New');
    });

    it('moves a folder under a new parent', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-move@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        $service = new MediaFolderService();
        $target = $service->create($principalId, 'Target');
        $folder = $service->create($principalId, 'Moved');

        simulateLoggedInSession($callerId, 'mf-move@example.com');
        $response = $controller->update(
            (string) $folder->id,
            folderRequest('PATCH', '/api/v1/media/folders/' . $folder->id, ['parent_id' => (string) $target->id]),
        );

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['data']['path'])->toBe('/Target/Moved');
    });

    it('answers 404 for a folder of another principal', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-foreign@example.com', MEDIA_FOLDER_PASSWORD);
        $otherId = bootAuth($auth, 'mf-foreign-other@example.com', MEDIA_FOLDER_PASSWORD);
        $foreignPrincipalId = $principalService->ensureUserPrincipal($otherId)->id;
        $folder = (new MediaFolderService())->create($foreignPrincipalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-foreign@example.com');
        $response = $controller->update(
            (string) $folder->id,
            folderRequest('PATCH', '/api/v1/media/folders/' . $folder->id, ['name' => 'Hijacked']),
        );

        expect($response->getStatusCode())->toBe(404);
    });

    it('answers 400 when the patch carries nothing to change', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-nopatch@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-nopatch@example.com');
        $response = $controller->update(
            (string) $folder->id,
            folderRequest('PATCH', '/api/v1/media/folders/' . $folder->id, []),
        );

        expect($response->getStatusCode())->toBe(400);
    });
});

describe('DELETE /api/v1/media/folders/{id}', function (): void {
    it('deletes a folder', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-delete@example.com', MEDIA_FOLDER_PASSWORD);
        $principalId = $principalService->ensureUserPrincipal($callerId)->id;
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-delete@example.com');
        $response = $controller->destroy((string) $folder->id);

        expect($response->getStatusCode())->toBe(200);
        expect(MediaFolder::query()->find($folder->id))->toBeNull();
    });

    it('answers 404 for a folder of another principal', function (): void {
        [$controller, $auth, $principalService] = buildFolderController();
        $callerId = bootAuth($auth, 'mf-del-foreign@example.com', MEDIA_FOLDER_PASSWORD);
        $otherId = bootAuth($auth, 'mf-del-other@example.com', MEDIA_FOLDER_PASSWORD);
        $foreignPrincipalId = $principalService->ensureUserPrincipal($otherId)->id;
        $folder = (new MediaFolderService())->create($foreignPrincipalId, 'Corporate');

        simulateLoggedInSession($callerId, 'mf-del-foreign@example.com');
        $response = $controller->destroy((string) $folder->id);

        expect($response->getStatusCode())->toBe(404);
        expect(MediaFolder::query()->find($folder->id))->not->toBeNull();
    });
});
