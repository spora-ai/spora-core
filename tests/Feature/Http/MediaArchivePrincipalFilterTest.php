<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaArchiveController;
use Spora\Models\Agent;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

defined('MEDIA_PRINCIPAL_FILTER_PASSWORD') || define('MEDIA_PRINCIPAL_FILTER_PASSWORD', 'Password1!');

/**
 * `?principal_id=` contract for `MediaArchiveController::index`.
 *
 * The controller must intersect the request values with the caller's
 * {@see PrincipalResolver::visiblePrincipalIds()} so an out-of-scope
 * principal id is silently dropped — mirroring the AgentController's
 * principal-filter behaviour. These tests pin that gate against the
 * real service + real DB rather than mocking the resolver, because the
 * security boundary lives at the resolver layer.
 */
function buildPrincipalFilterController(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-principal-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database  = new DatabaseAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service         = MediaArchiveTestSupport::buildService($assetStore);
    $principalResolver = new PrincipalResolver();
    $principalService = new PrincipalService($principalResolver);

    $auth = bootAuthLayer();

    return [
        new MediaArchiveController($service, $auth, new \Spora\Services\MediaArchive\MediaAssetSerializer(), $principalResolver),
        $auth,
        $principalService,
    ];
}

function seedMediaRow(string $id, ?int $agentId, ?int $userId): void
{
    $now = date('Y-m-d H:i:s');
    // Unique asset_token per row — `media_assets.asset_token` has a
    // UNIQUE index and `updateOrInsert` would surface a
    // UniqueConstraintViolationException if two seedMediaRow calls
    // shared a token.
    Capsule::table('media_assets')->updateOrInsert(
        ['id' => $id],
        [
            'asset_url'    => 'https://cdn.example/' . $id . '.png',
            'storage_mode' => 'external',
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'agent_id'     => $agentId,
            'user_id'      => $userId,
            'asset_token'  => bin2hex(random_bytes(16)),
            'migrated_from_inline_data_url' => false,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    );
}

beforeEach(function (): void {
    clearSession();
});
afterEach(function (): void {
    clearSession();
});

it('drops ?principal_id values the caller cannot see (out-of-scope principal is silently filtered)', function (): void {
    [$controller, $auth, $principalService] = buildPrincipalFilterController();

    // Caller + their user-principal.
    $callerId = bootAuth($auth, 'mpf-caller@example.com', MEDIA_PRINCIPAL_FILTER_PASSWORD);
    $userPrincipal = $principalService->ensureUserPrincipal($callerId);

    // A group the caller DOES belong to.
    $myGroupId = (int) Capsule::table('groups')->insertGetId([
        'name' => 'MPF My Group', 'description' => null, 'created_by_user_id' => $callerId,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $myGroupPrincipal = $principalService->ensureGroupPrincipal($myGroupId);
    GroupMembership::create([
        'group_id' => $myGroupId, 'user_id' => $callerId, 'role' => GroupMembership::ROLE_OWNER,
        'joined_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // A group the caller DOES NOT belong to.
    $otherId = bootAuth($auth, 'mpf-other@example.com', MEDIA_PRINCIPAL_FILTER_PASSWORD);
    $otherGroupId = (int) Capsule::table('groups')->insertGetId([
        'name' => 'MPF Other Group', 'description' => null, 'created_by_user_id' => $otherId,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $otherGroupPrincipal = $principalService->ensureGroupPrincipal($otherGroupId);

    // Three agents: caller-owned, caller's-group-owned, foreign-group-owned.
    $ownAgent = Agent::create([
        'principal_id' => $userPrincipal->id, 'name' => 'mpf-own',
        'max_steps' => 10, 'is_active' => 1,
    ]);
    $myGroupAgent = Agent::create([
        'principal_id' => $myGroupPrincipal->id, 'name' => 'mpf-mygroup',
        'max_steps' => 10, 'is_active' => 1,
    ]);
    $otherAgent = Agent::create([
        'principal_id' => $otherGroupPrincipal->id, 'name' => 'mpf-other',
        'max_steps' => 10, 'is_active' => 1,
    ]);

    seedMediaRow('00000000-0000-0000-0000-000000000001', $ownAgent->id, null);
    seedMediaRow('00000000-0000-0000-0000-000000000002', $myGroupAgent->id, null);
    seedMediaRow('00000000-0000-0000-0000-000000000003', $otherAgent->id, null);

    // Caller requests ALL three principals (their own, their group, the
    // foreign group). The foreign one must be dropped, leaving 2 rows.
    // We use the array-form `?principal_id[]=…` because Symfony's
    // `Request::create()` factory collapses repeated scalar query keys
    // down to the last value; the array form is the one that survives
    // the factory.
    simulateLoggedInSession($callerId, 'mpf-caller@example.com');
    $request = Request::create('/api/v1/media', 'GET', [
        'principal_id' => [
            $userPrincipal->id,
            $myGroupPrincipal->id,
            $otherGroupPrincipal->id,
        ],
    ]);

    $response = $controller->index($request);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    $ids = array_column($body['data']['assets'], 'id');
    expect($ids)->toContain('00000000-0000-0000-0000-000000000001');
    expect($ids)->toContain('00000000-0000-0000-0000-000000000002');
    expect($ids)->not->toContain('00000000-0000-0000-0000-000000000003');
    expect($body['data']['total'])->toBe(2);
});

it('falls back to the legacy ownership union when the caller passes only foreign principal ids', function (): void {
    // Pathological case: every supplied principal id is out-of-scope.
    // The intersection is empty so the controller must clear
    // `principalIds` and let the service fall back to the legacy
    // `agentOwnerUserId` ownership union. Otherwise the listing would
    // hard-return an empty result for a caller who legitimately has
    // media they should be able to see.
    [$controller, $auth, $principalService] = buildPrincipalFilterController();

    $callerId = bootAuth($auth, 'mpf-fallback@example.com', MEDIA_PRINCIPAL_FILTER_PASSWORD);
    $userPrincipal = $principalService->ensureUserPrincipal($callerId);

    // Foreign group.
    $otherId = bootAuth($auth, 'mpf-fb-other@example.com', MEDIA_PRINCIPAL_FILTER_PASSWORD);
    $otherGroupId = (int) Capsule::table('groups')->insertGetId([
        'name' => 'MPF FB Other', 'description' => null, 'created_by_user_id' => $otherId,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $foreignPrincipal = $principalService->ensureGroupPrincipal($otherGroupId);

    $ownAgent = Agent::create([
        'principal_id' => $userPrincipal->id, 'name' => 'mpf-fb-own',
        'max_steps' => 10, 'is_active' => 1,
    ]);
    seedMediaRow('00000000-0000-0000-0000-00000000000a', $ownAgent->id, $callerId);

    simulateLoggedInSession($callerId, 'mpf-fallback@example.com');
    $request = Request::create('/api/v1/media', 'GET', [
        'principal_id' => [$foreignPrincipal->id],
    ]);

    $response = $controller->index($request);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    $ids = array_column($body['data']['assets'], 'id');
    expect($ids)->toContain('00000000-0000-0000-0000-00000000000a');
    expect($body['data']['total'])->toBe(1);
});

it('returns the legacy union when no ?principal_id= is supplied (back-compat with older plugin builds)', function (): void {
    // Belt-and-braces: an older plugin that only sends the legacy
    // ?ownership=mine param must still get the same payload it always
    // did. The principalIds branch is opt-in via the new query param.
    [$controller, $auth, $principalService] = buildPrincipalFilterController();

    $callerId = bootAuth($auth, 'mpf-compat@example.com', MEDIA_PRINCIPAL_FILTER_PASSWORD);
    $userPrincipal = $principalService->ensureUserPrincipal($callerId);

    $ownAgent = Agent::create([
        'principal_id' => $userPrincipal->id, 'name' => 'mpf-compat-own',
        'max_steps' => 10, 'is_active' => 1,
    ]);
    seedMediaRow('00000000-0000-0000-0000-00000000000b', $ownAgent->id, $callerId);

    simulateLoggedInSession($callerId, 'mpf-compat@example.com');
    $request = Request::create('/api/v1/media', 'GET');

    $response = $controller->index($request);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['total'])->toBe(1);
});
