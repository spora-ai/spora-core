<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| This file is the entry point for Pest. Global test utilities, helpers,
| and dataset definitions live here.
|
*/

// Ensure BASE_PATH is defined for all tests
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Fixture plugins are organised by test purpose (e.g. plugins_inventory/<Plugin>)
// rather than by namespace, so Composer's `Tests\` → `tests/` mapping can't find
// them. Register each fixture explicitly so `is_a(..., true)` in PluginLoader
// can resolve the class.
$classLoader = require BASE_PATH . '/vendor/autoload.php';
$fixtures   = BASE_PATH . '/tests/Fixtures';
foreach ([
    'ManifestPlugin'    => 'plugins_with_manifest/ManifestPlugin',
    'ToolsPlugin'       => 'plugins_with_tools/ToolsPlugin',
    'MigratingPlugin'   => 'plugins_with_migrations/MigratingPlugin',
    'InventoryPlugin'   => 'plugins_inventory/InventoryPlugin',
    'DefaultIconPlugin' => 'plugins_inventory_brain/DefaultIconPlugin',
    'BadPrefixPlugin'   => 'plugins_bad_migrations/BadPrefixPlugin',
    'NamedPlugin'       => 'plugins_with_custom_file/NamedPlugin',
    'NotAPlugin'        => 'plugins_invalid_manifest/NotAPlugin',
] as $plugin => $relativePath) {
    $classLoader->addPsr4('Tests\\Fixtures\\Plugins\\' . $plugin . '\\', $fixtures . '/' . $relativePath . '/');
}

// Suppress E_DEPRECATED originating from delight-im vendor packages.
// delight-im/auth v9.0.0 uses implicit nullable types (e.g. `callable $x = null`)
// which PHP 8.4+ deprecates. The maintainer has acknowledged this (GitHub #314)
// but defers the fix to preserve PHP 7.0 compatibility. The warnings are harmless
// — nothing breaks at runtime — so we silence them here rather than patching vendor.
set_error_handler(static function (int $errno, string $_errstr, string $errfile): bool {
    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);

// Shared test helpers (available to all test files)

require_once __DIR__ . '/Support/AgentTemplateTestSupport.php';

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): Spora\Auth\AuthService
{
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new Spora\Auth\AuthService($auth);
}

/**
 * Create a JSON Request with an optional body array.
 */
function jsonRequest(string $method, string $uri, array $body = []): Symfony\Component\HttpFoundation\Request
{
    $content = $body !== [] ? json_encode($body) : '';

    return Symfony\Component\HttpFoundation\Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * Simulate a logged-in session by populating the PHP session superglobal
 * the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME]  = null;
}

/**
 * Clear the session, simulating a not-logged-in state.
 */
function clearSession(): void
{
    $_SESSION = [];
}

/**
 * Register a new user and simulate their session.
 * Returns the user ID.
 */
function bootAuth(Spora\Auth\AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

/**
 * Invoke a controller method through the middleware pipeline (for testing).
 * This mimics what Router::handleFound() does at runtime.
 *
 * @param object $controller The controller instance
 * @param string $method The method name to call
 * @param Symfony\Component\HttpFoundation\Request $request The request
 * @param array<int, object> $middleware List of middleware instances to apply (in order)
 * @return Symfony\Component\HttpFoundation\Response
 */
function callController(object $controller, string $method, Symfony\Component\HttpFoundation\Request $request, array $middleware = []): Symfony\Component\HttpFoundation\Response
{
    $vars = [];
    if (preg_match_all('/\{([^}]+)\}/', $request->getPathInfo(), $matches)) {
        foreach ($matches[1] as $name) {
            $vars[$name] = $request->attributes->get($name);
        }
    }
    // Also include attributes that were set directly (e.g., in tests)
    foreach ($request->attributes->all() as $name => $value) {
        if (!isset($vars[$name])) {
            $vars[$name] = $value;
        }
    }

    $next = function () use ($controller, $method, $vars, $request): Symfony\Component\HttpFoundation\Response {
        $params = (new ReflectionMethod($controller, $method))->getParameters();
        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();
            $isRequest = $type instanceof ReflectionNamedType
                && is_a($type->getName(), Symfony\Component\HttpFoundation\Request::class, true);
            if ($isRequest) {
                $args[] = $request;
                continue;
            }
            $name = $param->getName();
            if (isset($vars[$name])) {
                $value = $vars[$name];
                if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
                    $value = (int) $value;
                }
                $args[] = $value;
            }
        }
        return $controller->$method(...$args);
    };

    // Auto-attach CSRF token from session if present (mirrors production behavior)
    if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
        $request->headers->set('X-CSRF-Token', $_SESSION['csrf_token']);
    }

    foreach (array_reverse($middleware) as $mw) {
        $currentNext = $next;
        $next = function () use ($mw, $request, $currentNext): Symfony\Component\HttpFoundation\Response {
            return $mw->handle($request, $currentNext);
        };
    }

    return $next();
}

uses()
    ->beforeEach(function () {
        Spora\Core\Database::resetBootState();
        $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
        $db->boot();
        Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
            Illuminate\Database\Capsule\Manager::connection()->rollBack();
        }
        Spora\Core\Database::resetBootState();
    })
    ->in(__DIR__);

/**
 * Ensure BASE_PATH/config.php has 'key_path' => null so test containers
 * that resolve SecurityManager from the real file exercise the
 * MissingSecretKeyException code path. Called via the global beforeEach.
 */
function resetSporaConfigKeyPath(): void
{
    $configPath = BASE_PATH . '/config.php';
    if (! is_file($configPath)) {
        return;
    }

    $source = file_get_contents($configPath);
    if ($source === false) {
        return;
    }

    $updated = preg_replace(
        "/('key_path'\s*=>\s*)(?:'[^']*'|[^,]+)(,)/",
        "\$1null\$2",
        $source,
        1,
    );

    if ($updated !== null && $updated !== $source) {
        file_put_contents($configPath, $updated);
    }
}
