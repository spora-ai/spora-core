<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\Core\Kernel;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Regression coverage for the admin-only gate on the Web UI plugin install
 * surface. The plugin routes (`POST` / `DELETE` / `PATCH /api/v1/plugins*`)
 * must be registered with `AdminMiddleware` in their middleware stack.
 *
 * Three layers of coverage:
 *   1. Route registration (this file) — locks in the wiring declared in
 *      `app/Core/RouteDefinitions.php`. This is the source of truth.
 *   2. AdminMiddleware behaviour (tests/Unit/Http/Middleware/AdminMiddlewareTest.php).
 *   3. End-to-end through `Kernel::handle()` for the routes that can be
 *      reached without path-variable ambiguity (the POST route).
 *
 * Most tests run with `SPORA_PLUGIN_INSTALL_ENABLED=true` so the admin gate
 * is provably the *first* gate a request hits. One test (`AdminMiddleware
 * runs BEFORE the feature flag gate`) explicitly flips the flag off to
 * prove that AdminMiddleware runs before `requireInstallEnabled()`.
 */

const PLUGIN_ADMIN_GATE_PASSWORD = 'Password1!';
const PLUGIN_ADMIN_GATE_INSTALL_PATH = '/api/v1/plugins';
const PLUGIN_ADMIN_GATE_PKG = 'spora-ai/spora-plugin-tavily';

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
    $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = 'true';
});

afterEach(function (): void {
    unset($_ENV['SPORA_SECRET_KEY'], $_ENV['SPORA_PLUGIN_INSTALL_ENABLED']);
    $_SESSION = [];
    Spora\Core\Database::resetBootState();
});

/**
 * Read `app/Core/RouteDefinitions.php` and return the registered middleware
 * list for each plugin route (POST/DELETE/PATCH on `/api/v1/plugins*`).
 *
 * The MiddlewareRouteCollector does not expose its internal route data once
 * `addRoute()` has been called, so a direct read of the source is the most
 * robust way to lock in the wiring. Parsing is line-oriented: each plugin
 * route lives on its own `addRoute(...)` line, and the middleware list is
 * the FINAL `[ Class1::class, … ]` literal on that line. Unqualified class
 * names are resolved against the `use …;` statements at the top of the file
 * so callers can compare to FQCN::class constants.
 *
 * @return array<string, list<string>>  key = "<METHOD> <pattern>", value = middleware FQCNs
 */
function pluginAdminGate_readRouteDefinitions(): array
{
    $source = file_get_contents(__DIR__ . '/../../app/Core/RouteDefinitions.php');
    expect($source)->toBeString();

    // Collect `use Spora\Http\Middleware\Foo;` → Foo → Spora\Http\Middleware\Foo
    $aliases = [];
    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+(?:\s*\\\\[A-Za-z0-9_]+)*)\s*;\s*$/m', $source, $useMatchesFull);
    foreach ($useMatchesFull[1] as $fqcn) {
        $parts = explode('\\', $fqcn);
        $short = end($parts);
        $aliases[$short] = $fqcn;
    }

    $found = [];
    foreach (preg_split('/\R/', $source) as $line) {
        if (!preg_match("/addRoute\(\s*'([A-Z]+)'\s*,\s*'([^']+)'/", $line, $m)) {
            continue;
        }
        $method = $m[1];
        $route  = $m[2];
        if (!str_starts_with($route, '/api/v1/plugins')) {
            continue;
        }
        if (!preg_match('/\[([^\[\]]*::class(?:\s*,\s*[A-Za-z0-9_\\\\]+::class)*)\]\s*\)\s*;\s*$/', $line, $mm)) {
            continue;
        }
        preg_match_all('/([A-Za-z0-9_\\\\]+::class)/', $mm[1], $mwMatches);
        $resolved = [];
        foreach ($mwMatches[1] as $classRef) {
            // $classRef is e.g. "AdminMiddleware::class"; resolve the short name
            // and strip the trailing "::class" so callers can compare to PHP's
            // `Foo::class` magic constant (which resolves to the FQCN without
            // the `::class` suffix).
            $short = substr($classRef, 0, -strlen('::class'));
            $resolved[] = $aliases[$short] ?? $short;
        }
        $found["{$method} {$route}"] = $resolved;
    }
    return $found;
}

/**
 * Register a user and seed the session. Does NOT grant admin by default;
 * callers grant admin explicitly when needed.
 */
function pluginAdminGate_makeUser(string $email, bool $admin): int
{
    $auth = bootAuthLayer();
    $id = $auth->register($email, PLUGIN_ADMIN_GATE_PASSWORD, $email);
    if ($admin) {
        $auth->grantRole($id, Role::ADMIN);
    }
    simulateLoggedInSession($id, $email);
    return $id;
}

/**
 * State-changing request with a valid CSRF token (the CsrfMiddleware is
 * upstream of AdminMiddleware in the stack — see RouteDefinitions.php:64-66).
 */
function pluginAdminGate_stateChangeRequest(string $method, string $path, ?array $body = null): Request
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $server = ['HTTP_X_CSRF_TOKEN' => $_SESSION['csrf_token']];
    if ($body !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        return Request::create($path, $method, [], [], [], $server, json_encode($body));
    }
    return Request::create($path, $method, [], [], [], $server);
}

/**
 * Decode a JSON response body and assert it is a non-empty array with the
 * standard `{ "error": { "code": …, "message": … } }` envelope shape.
 * `$body` is the array; the `code` is returned so callers can assert on
 * it without re-indexing. Keeps every test failure message clear (vs. a
 * raw `Cannot access offset on null` PHP error).
 *
 * @return string  the `error.code` value
 */
function pluginAdminGate_decodeErrorCode(string $rawContent): string
{
    $body = json_decode($rawContent, true);
    expect($body)->toBeArray();
    expect($body)->toHaveKey('error');
    expect($body['error'])->toBeArray();
    expect($body['error'])->toHaveKey('code');
    expect($body['error']['code'])->toBeString();
    return $body['error']['code'];
}

// Route registration — locks in the wiring declared in RouteDefinitions.

test('POST /api/v1/plugins is registered with AdminMiddleware in its middleware stack', function (): void {
    $routes = pluginAdminGate_readRouteDefinitions();

    expect($routes)->toHaveKey('POST /api/v1/plugins');
    expect($routes['POST /api/v1/plugins'])->toContain(AdminMiddleware::class);
    // The order must be Auth → Csrf → Admin so a request hits auth first,
    // then CSRF, then admin. Reversing Admin → Csrf would still pass the
    // simpler "contains AdminMiddleware" check above but would mean a CSRF
    // token bypass could be attempted.
    expect($routes['POST /api/v1/plugins'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ]);
});

test('DELETE /api/v1/plugins/{package} is registered with AdminMiddleware in its middleware stack', function (): void {
    $routes = pluginAdminGate_readRouteDefinitions();

    expect($routes)->toHaveKey('DELETE /api/v1/plugins/{package}');
    expect($routes['DELETE /api/v1/plugins/{package}'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ]);
});

test('PATCH /api/v1/plugins/{package} is registered with AdminMiddleware in its middleware stack', function (): void {
    $routes = pluginAdminGate_readRouteDefinitions();

    expect($routes)->toHaveKey('PATCH /api/v1/plugins/{package}');
    expect($routes['PATCH /api/v1/plugins/{package}'])->toBe([
        AuthMiddleware::class,
        CsrfMiddleware::class,
        AdminMiddleware::class,
    ]);
});

// End-to-end through Kernel::handle() for the routes that can be hit
// without path-variable ambiguity. The DELETE/PATCH routes have a separate
// fast-route `{package}` quirk (the placeholder doesn't decode `%2F`, so a
// vendor/name slug doesn't match the URL pattern) — that's a URL-routing
// concern, not an admin-enforcement concern. The route-registration tests
// above lock in the admin gate for all three routes.

test('POST /api/v1/plugins returns 403 FORBIDDEN for a logged-in non-admin (end-to-end)', function (): void {
    pluginAdminGate_makeUser('non-admin-post@example.com', admin: false);

    $kernel   = new Kernel();
    $response = $kernel->handle(pluginAdminGate_stateChangeRequest(
        'POST',
        PLUGIN_ADMIN_GATE_INSTALL_PATH,
        ['package' => PLUGIN_ADMIN_GATE_PKG],
    ));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403);

    // Distinct from 403 FEATURE_DISABLED — proves AdminMiddleware ran before
    // the controller's requireInstallEnabled().
    expect(pluginAdminGate_decodeErrorCode($response->getContent()))->toBe('FORBIDDEN');
});

test('AdminMiddleware runs BEFORE the feature flag gate (non-admin gets FORBIDDEN, not FEATURE_DISABLED, when install flag is also off)', function (): void {
    // With the feature flag off, the controller would normally throw
    // FeatureDisabledException → 403 FEATURE_DISABLED. Asserting FORBIDDEN
    // proves AdminMiddleware runs before requireInstallEnabled() — the two
    // 403 codes are distinguishable to the client and to this test.
    $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = 'false';
    pluginAdminGate_makeUser('non-admin-feature-off@example.com', admin: false);

    $kernel   = new Kernel();
    $response = $kernel->handle(pluginAdminGate_stateChangeRequest(
        'POST',
        PLUGIN_ADMIN_GATE_INSTALL_PATH,
        ['package' => PLUGIN_ADMIN_GATE_PKG],
    ));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403);

    $code = pluginAdminGate_decodeErrorCode($response->getContent());
    expect($code)->toBe('FORBIDDEN');
    expect($code)->not->toBe('FEATURE_DISABLED');
});

test('POST /api/v1/plugins reaches the controller for a logged-in admin (no 403)', function (): void {
    // Positive lock-in: admin path is not blocked. The controller may then
    // succeed or fail downstream (e.g. composer missing in the test
    // container) — the assertion is only that the admin gate passed.
    pluginAdminGate_makeUser('admin-reaches@example.com', admin: true);

    $kernel   = new Kernel();
    $response = $kernel->handle(pluginAdminGate_stateChangeRequest(
        'POST',
        PLUGIN_ADMIN_GATE_INSTALL_PATH,
        ['package' => PLUGIN_ADMIN_GATE_PKG],
    ));
    $kernel->__destruct();

    expect($response->getStatusCode())->not->toBe(403);

    // If the response is an error envelope, it must NOT be FORBIDDEN.
    // (A 500 from a missing composer binary, etc., is acceptable — the
    // admin gate passed, which is what we're proving.)
    if (str_contains($response->headers->get('Content-Type', '') ?: '', 'application/json')) {
        $body = json_decode($response->getContent(), true);
        if (is_array($body) && isset($body['error']['code']) && is_string($body['error']['code'])) {
            expect($body['error']['code'])->not->toBe('FORBIDDEN');
        }
    }
});

test('Admin POST returns 403 FEATURE_DISABLED when the install flag is off (the controller gate is reachable after admin)', function (): void {
    // With the install flag off AND an admin session, the request should
    // get past AdminMiddleware, into the controller, where
    // requireInstallEnabled() throws FeatureDisabledException → 403
    // FEATURE_DISABLED. This is the OTHER half of the gating story —
    // proving the controller's feature flag is reachable from the admin
    // path (i.e. admin middleware runs first, controller runs second).
    $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = 'false';
    pluginAdminGate_makeUser('admin-feature-off@example.com', admin: true);

    $kernel   = new Kernel();
    $response = $kernel->handle(pluginAdminGate_stateChangeRequest(
        'POST',
        PLUGIN_ADMIN_GATE_INSTALL_PATH,
        ['package' => PLUGIN_ADMIN_GATE_PKG],
    ));
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403);

    $code = pluginAdminGate_decodeErrorCode($response->getContent());
    expect($code)->toBe('FEATURE_DISABLED');
});
