<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaResolveController;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Models\Task;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
    clearSession();
});

/**
 * Plan §4.1 — MediaResolveController end-to-end surface tests.
 *
 * Pins the existence-hiding semantics (a foreign id silently dropped, never
 * 404/403), the cap-and-validation contract, and the wire-shape returned on
 * the happy path.
 */
test('resolve returns 200 with the wire-shape asset for each owned id', function (): void {
    [$service, $userId, $controller] = buildResolveFixtures();
    $owned = ingestAsset($service, $userId, 'owned.png', 'image/png');
    $foreign = ingestAsset($service, 9999, 'foreign.png', 'image/png');

    $resp = $controller->resolve(jsonPost(['ids' => [$owned->id, $foreign->id]]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['assets'])->toHaveCount(1)
        ->and($body['data']['assets'][0]['id'])->toBe($owned->id);
});

test('resolve omits unknown ids without 404 (existence-hiding)', function (): void {
    [, , $controller] = buildResolveFixtures();
    $resp = $controller->resolve(jsonPost(['ids' => ['00000000-0000-4000-8000-000000000000']]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect(json_decode($resp->getContent(), true)['data']['assets'])->toBe([]);
});

test('resolve returns 401 when the caller is not logged in', function (): void {
    // Build the controller while logged in (fixtures seed a principal row
    // that depends on a session-bearing user), then drop the session so
    // the controller sees an anonymous caller.
    [, , $controller] = buildResolveFixtures();
    clearSession();
    $resp = $controller->resolve(jsonPost(['ids' => ['00000000-0000-4000-8000-000000000000']]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHORIZED');
});

test('resolve returns 422 when ids is missing', function (): void {
    [, , $controller] = buildResolveFixtures();
    $resp = $controller->resolve(jsonPost([]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('resolve returns 422 when ids is empty', function (): void {
    [, , $controller] = buildResolveFixtures();
    $resp = $controller->resolve(jsonPost(['ids' => []]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('resolve returns 422 when ids contains a non-UUID string', function (): void {
    [, , $controller] = buildResolveFixtures();
    $resp = $controller->resolve(jsonPost(['ids' => ['not-a-uuid']]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('resolve returns 422 when ids exceeds the cap (64)', function (): void {
    [, , $controller] = buildResolveFixtures();
    $ids = [];
    for ($i = 0; $i < 65; $i++) {
        $ids[] = sprintf('00000000-0000-4000-8000-%012d', $i);
    }
    $resp = $controller->resolve(jsonPost(['ids' => $ids]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('resolve returns 422 when ids is not an array', function (): void {
    [, , $controller] = buildResolveFixtures();
    $resp = $controller->resolve(jsonPost(['ids' => 'abc']));
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('resolve preserves the input id order in the response', function (): void {
    [$service, $userId, $controller] = buildResolveFixtures();
    $a = ingestAsset($service, $userId, 'a.png', 'image/png');
    $b = ingestAsset($service, $userId, 'b.png', 'image/png');
    $c = ingestAsset($service, $userId, 'c.png', 'image/png');

    $resp = $controller->resolve(jsonPost(['ids' => [$c->id, $a->id, $b->id]]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $ids = array_column(json_decode($resp->getContent(), true)['data']['assets'], 'id');
    expect($ids)->toBe([$c->id, $a->id, $b->id]);
});

test('resolve allows group-member visibility via PrincipalResolver::isVisibleTo', function (): void {
    [$service, $ownerUserId, $controller] = buildResolveFixtures();
    $agent = Agent::query()->where('principal_id', Principal::where('type', Principal::TYPE_USER)->where('user_id', $ownerUserId)->value('id'))->first();
    // Seed an agent-owned, tool-generated asset (no `user_id`, attached to
    // the owner's agent) — owner should still see it via the principal
    // path that `PrincipalResolver::isVisibleTo` exposes.
    $asset = ingestAgentAsset($service, $agent, 'agent.png');

    $resp = $controller->resolve(jsonPost(['ids' => [$asset->id]]));
    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    expect(json_decode($resp->getContent(), true)['data']['assets'])->toHaveCount(1);
});

/**
 * @return array{0: MediaArchiveService, 1: int, 2: MediaResolveController}
 */
function buildResolveFixtures(): array
{
    $tmp = sys_get_temp_dir() . '/spora-resolve-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $auth = bootAuthLayer();
    $userId = bootAuth($auth, 'resolve@example.com', 'Password1!', 'Resolver');
    $userPrincipalId = (int) Principal::where('type', Principal::TYPE_USER)->where('user_id', $userId)->value('id');
    if ($userPrincipalId <= 0) {
        $userPrincipalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($userId)->id;
    }
    // Seed a default agent for the principal so the asset-owner path has a row.
    if (Agent::query()->where('principal_id', $userPrincipalId)->doesntExist()) {
        Agent::query()->insert([
            'principal_id' => $userPrincipalId,
            'name' => 'resolver-agent',
            'description' => '',
            'system_prompt' => '',
            'llm_driver_config_id' => null,
            'max_steps' => 5,
            'is_active' => 1,
            'allow_followup' => 1,
            'retry_after_minutes' => 0,
            'max_retries' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    if (Task::query()->where('principal_id', $userPrincipalId)->doesntExist()) {
        Task::query()->insert([
            'principal_id' => $userPrincipalId,
            'trigger_user_id' => $userId,
            'agent_id' => Agent::query()->where('principal_id', $userPrincipalId)->value('id'),
            'status' => 'COMPLETED',
            'user_prompt' => 'hi',
            'step_count' => 0,
            'max_steps' => 5,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);

    $controller = new MediaResolveController(
        $service,
        $auth,
        new MediaAssetSerializer(includeDerivatives: false),
    );
    return [$service, $userId, $controller];
}

function ingestAsset(MediaArchiveService $service, int $userId, string $name, string $mime): MediaAsset
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    return $service->ingest(new MediaIngestRequest(
        bytes: $mime === 'text/plain' ? 'hello' : $png,
        mime: $mime,
        filename: $name,
        userId: $userId,
        uploadSource: 'upload',
    ));
}

function ingestAgentAsset(MediaArchiveService $service, Agent $agent, string $name): MediaAsset
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    return $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: $name,
        userId: null,
        agentId: $agent->id,
        uploadSource: 'tool',
    ));
}

function jsonPost(array $body): Request
{
    return Request::create(
        '/api/v1/media/resolve',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
}
