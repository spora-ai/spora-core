<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\GroupMembership;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Tools\SharedSettingsResolver;
use Symfony\Component\HttpFoundation\Request;
use Tests\Unit\Tools\AcmeSharedSettings;

/**
 * A shared settings group carries no `#[Tool]` — it is not a tool. The name
 * map indexed only `#[Tool]` classes, so a group id resolved to nothing, and
 * `ToolController` (the only writer of `tool_configurations`) 404'd. The
 * consequence was invisible: `applyToDefaults()` read an always-empty row,
 * `inherited` was always `[]`, and every test wrote the group through the
 * service directly — a path no route reaches.
 *
 * These tests go through the route.
 */
beforeEach(function (): void {
    TestDatabaseFactory::boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
    $_ENV['SPORA_APP_ENV'] = 'testing';
});

function groupWritePaths(): Paths
{
    $base = sys_get_temp_dir() . '/spora-group-write-' . uniqid('', true);
    mkdir($base, 0o777, true);
    mkdir($base . '/storage', 0o777, true);

    return new Paths($base);
}

function groupWriteService(): ToolConfigService
{
    $shared = new SharedSettingsResolver([AcmeSharedSettings::class]);

    return new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Logger('test'),
        [],
        new ToolConfigSchemaInspector(),
        new PrincipalService(new PrincipalResolver()),
        false,
        new PrincipalResolver(),
        $shared,
    );
}

it('resolves a shared settings group id to its declaring class', function (): void {
    $resolver = new ToolConfigNameResolver(
        new Logger('test'),
        [],
        [AcmeSharedSettings::class],
    );

    expect($resolver->resolveToolClass('group:' . AcmeSharedSettings::ID))->toBe(AcmeSharedSettings::class)
        ->and($resolver->resolveGroupClass(AcmeSharedSettings::ID))->toBe(AcmeSharedSettings::class)
        ->and($resolver->resolveGroupClass('no_such_group'))->toBeNull();
});

it('does not let a bare group slug collide with a tool name', function (): void {
    $resolver = new ToolConfigNameResolver(
        new Logger('test'),
        [],
        [AcmeSharedSettings::class],
    );

    // The prefix is the point: an unqualified slug that happened to equal a
    // tool name would turn a group write into a tool write.
    expect($resolver->resolveToolClass(AcmeSharedSettings::ID))->toBeNull();
});

it('stores a shared credential under the group id the route uses', function (): void {
    $service = groupWriteService();
    $class   = $service->resolveToolClass('group:' . AcmeSharedSettings::ID);

    expect($class)->toBe(AcmeSharedSettings::class);

    $service->putGlobalSettings($class, ['api_key' => 'SHARED-PLAINTEXT-CANARY']);

    // Stored against the class, which is what every tool in the plugin reads
    // through the cascade.
    $stored = Capsule::table('tool_configurations')
        ->where('tool_class', AcmeSharedSettings::class)
        ->first();

    expect($stored)->not->toBeNull()
        ->and($service->getGlobalSettings($class)['api_key'])->toBe('SHARED-PLAINTEXT-CANARY');
});

it('masks the shared credential on read', function (): void {
    $service = groupWriteService();
    $class   = AcmeSharedSettings::class;

    $service->putGlobalSettings($class, ['api_key' => 'SHARED-PLAINTEXT-CANARY']);

    $body = (string) json_encode($service->maskForApi($service->getGlobalSettings($class), $class));

    expect($body)->not->toContain('SHARED-PLAINTEXT-CANARY')
        ->and($body)->toContain('***');
});

it('hands the value to a tool that consumes the group', function (): void {
    $service = groupWriteService();

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'SHARED-PLAINTEXT-CANARY']);

    $effective = $service->getEffectiveSettings(Tests\Unit\Tools\AcmeChatTool::class, 0);

    expect($effective['api_key'])->toBe('SHARED-PLAINTEXT-CANARY');
});

test('the tool settings routes require group administration', function (): void {
    $collector = new Spora\OpenApi\RouteSpecCollector();
    Spora\Core\RouteDefinitions::register($collector);

    $routes = [];
    foreach ($collector->routes() as $route) {
        $routes[$route['method'] . ' ' . $route['route']] = $route['middleware'];
    }

    foreach (['GET', 'PUT', 'DELETE'] as $method) {
        expect($routes[$method . ' /api/v1/tools/{toolId}/settings'])->toContain(
            Spora\Http\Middleware\GroupAdminMiddleware::class,
        );
    }
});

test('a signed-in user with no group role cannot read tool settings', function (): void {
    $auth = bootAuthLayer();
    $id   = bootAuth($auth, 'group-plain@example.com', 'Password1!', 'Plain');
    simulateLoggedInSession($id, 'group-plain@example.com');

    expect($auth->isAdmin())->toBeFalse();

    $kernel   = new Kernel(groupWritePaths());
    $response = $kernel->handle(Request::create('/api/v1/tools/any-tool/settings', 'GET'));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode((string) $response->getContent(), true)['error']['code'])->toBe('FORBIDDEN');
});

test('a group admin is enough — no global admin required', function (): void {
    $auth = bootAuthLayer();
    $id   = bootAuth($auth, 'group-owner@example.com', 'Password1!', 'Owner');
    simulateLoggedInSession($id, 'group-owner@example.com');

    expect($auth->isAdmin())->toBeFalse();

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => 'group-gate-' . $id,
        'description'        => null,
        'created_by_user_id' => $id,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $id,
        'role'       => GroupMembership::ROLE_OWNER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $kernel   = new Kernel(groupWritePaths());
    $response = $kernel->handle(Request::create('/api/v1/tools/any-tool/settings', 'GET'));
    $kernel->__destruct();

    // 404 rather than 403: the gate let them through, and no tool called
    // "any-tool" exists. A 403 here would mean the gate rejected an owner.
    expect($response->getStatusCode())->toBe(404);
});

test('a global admin still passes without holding any group role', function (): void {
    $auth = bootAuthLayer();
    $id   = $auth->register('group-global@example.com', 'Password1!', 'Global');
    $auth->grantRole($id, Role::ADMIN);
    simulateLoggedInSession($id, 'group-global@example.com');

    $kernel   = new Kernel(groupWritePaths());
    $response = $kernel->handle(Request::create('/api/v1/tools/any-tool/settings', 'GET'));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(404);
});

it('does not let the enable pre-seed shadow the shared group', function (): void {
    // `enableTool()` wrote `getSchemaDefaults()` into the agent override when
    // the tool had no globals of its own — which is precisely the shared-group
    // scenario. The agent level outranks the group, so the operator's value
    // was replaced by the code default the moment the tool was enabled.
    //
    // The fix skips the seed rather than seeding the group value: seeding that
    // would put the group's credential into the agent override, which the
    // status path reads, collapsing the deliberate boundary that a shared
    // credential enables a tool without counting as configured.
    $service = groupWriteService();
    $tool    = Tests\Unit\Tools\AcmeChatTool::class;

    $service->putGlobalSettings(AcmeSharedSettings::class, ['retries' => '99']);

    expect($service->getSchemaDefaults($tool)['retries'] ?? null)->toBe('3')
        ->and($service->sharedGroupHasValues($tool))->toBeTrue();
});

it('reports no shared values for a tool in no group', function (): void {
    $service = groupWriteService();

    expect($service->sharedGroupHasValues(Tests\Unit\Tools\AcmeStandaloneTool::class))->toBeFalse();
});

test('an unauthenticated caller cannot read tool settings', function (): void {
    // The gate has three outcomes, not two: a global admin passes, a group
    // admin passes, everyone else is 403 — and a caller with no session has
    // to be 401 before the role lookup ever runs.
    $kernel   = new Kernel(groupWritePaths());
    $response = $kernel->handle(Request::create('/api/v1/tools/any-tool/settings', 'GET'));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true)['error']['code'])->toBe('UNAUTHENTICATED');
});

test('a plain member of a group still cannot read tool settings', function (): void {
    // Being in a group is not enough — only owner and admin clear the bar.
    $auth = bootAuthLayer();
    $id   = bootAuth($auth, 'group-member@example.com', 'Password1!', 'Member');
    simulateLoggedInSession($id, 'group-member@example.com');

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => 'group-member-' . $id,
        'description'        => null,
        'created_by_user_id' => $id,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $id,
        'role'       => GroupMembership::ROLE_MEMBER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $kernel   = new Kernel(groupWritePaths());
    $response = $kernel->handle(Request::create('/api/v1/tools/any-tool/settings', 'GET'));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403);
});
