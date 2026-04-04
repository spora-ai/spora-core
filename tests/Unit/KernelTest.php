<?php

declare(strict_types=1);

use DI\Container;
use Spora\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('Kernel instantiates without throwing', function (): void {
    expect(fn() => new Kernel())->not()->toThrow(Throwable::class);
});

test('getContainer() returns a DI Container instance', function (): void {
    $kernel = new Kernel();

    expect($kernel->getContainer())->toBeInstanceOf(Container::class);
});

// ---------------------------------------------------------------------------
// 404 Not Found
// ---------------------------------------------------------------------------

test('GET to unknown route returns 404 JSON with correct envelope shape', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/this-route-does-not-exist', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(404);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error'])->toHaveKey('code');
    expect($body['error'])->toHaveKey('message');
    expect($body['error']['code'])->toBe('NOT_FOUND');
});

test('POST to unknown route returns 404 JSON', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/nonexistent', 'POST');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(404);
});

test('404 response has Content-Type application/json', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/nope', 'GET'));

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

// ---------------------------------------------------------------------------
// 405 Method Not Allowed
// ---------------------------------------------------------------------------

test('wrong HTTP method on known route returns 405 JSON with METHOD_NOT_ALLOWED code', function (): void {
    $kernel  = new Kernel();
    // /api/v1/auth/login only accepts POST
    $request  = Request::create('/api/v1/auth/login', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(405);

    $body = json_decode($response->getContent(), true);

    expect($body['error']['code'])->toBe('METHOD_NOT_ALLOWED');
});

test('405 response body contains a message string', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/api/v1/auth/login', 'GET'));
    $body     = json_decode($response->getContent(), true);

    expect($body['error'])->toHaveKey('message');
    expect($body['error']['message'])->toBeString()->not()->toBeEmpty();
});

test('405 response has Content-Type application/json', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/api/v1/auth/login', 'DELETE'));

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

// ---------------------------------------------------------------------------
// Successful dispatch
// ---------------------------------------------------------------------------

test('valid route with correct method dispatches to controller and returns a response', function (): void {
    $kernel   = new Kernel();
    // POST /api/v1/auth/login is routed to AuthController::login (stub returns 501)
    $request  = Request::create('/api/v1/auth/login', 'POST');
    $response = $kernel->handle($request);

    // Must not be a routing error — controller was reached
    expect($response->getStatusCode())->not()->toBe(404);
    expect($response->getStatusCode())->not()->toBe(405);
});

test('dispatched stub controller returns JSON with error envelope', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/login', 'POST');
    $response = $kernel->handle($request);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error'])->toHaveKey('code');
});

// ---------------------------------------------------------------------------
// Exception handling (500)
// ---------------------------------------------------------------------------

// Helper: force SecurityManager to throw regardless of whether a .env file is present.
// Dotenv::createImmutable (used by Kernel::loadDotEnv) will NOT override a variable that is
// already defined in the environment, so injecting an invalid key before constructing the
// Kernel is safe — the .env value is skipped and the container always sees our bad value.
// '!invalid!' contains '!' which is not in the base64 alphabet, so strict base64_decode
// returns false → RuntimeException in the SecurityManagerInterface factory → 500.
function withoutSecretKey(callable $fn): mixed
{
    $savedKey     = $_ENV['SPORA_SECRET_KEY'] ?? null;
    $savedKeyPath = $_ENV['SPORA_KEY_PATH'] ?? null;

    $_ENV['SPORA_SECRET_KEY'] = '!invalid!';
    putenv('SPORA_SECRET_KEY=!invalid!');
    // Remove key-path so the factory doesn't fall through to a file.
    unset($_ENV['SPORA_KEY_PATH']);
    putenv('SPORA_KEY_PATH');

    try {
        return $fn();
    } finally {
        if ($savedKey !== null) {
            $_ENV['SPORA_SECRET_KEY'] = $savedKey;
            putenv("SPORA_SECRET_KEY={$savedKey}");
        } else {
            unset($_ENV['SPORA_SECRET_KEY']);
            putenv('SPORA_SECRET_KEY');
        }
        if ($savedKeyPath !== null) {
            $_ENV['SPORA_KEY_PATH'] = $savedKeyPath;
            putenv("SPORA_KEY_PATH={$savedKeyPath}");
        }
    }
}

test('uncaught controller exception returns 500 JSON', function (): void {
    // GET /api/v1/agent resolves AgentController → ToolConfigService → SecurityManagerInterface.
    // Without any secret key configured, SecurityManager throws → Kernel catches → 500.
    $response = withoutSecretKey(static function (): mixed {
        $kernel = new Kernel();
        return $kernel->handle(Request::create('/api/v1/agents', 'GET'));
    });

    expect($response->getStatusCode())->toBe(500);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error']['code'])->toBe('INTERNAL_SERVER_ERROR');
    expect($body['error'])->toHaveKey('message');
});

test('500 response in production mode does not expose exception details', function (): void {
    // Pin app_env to production regardless of local .env so the debug block assertion is stable.
    $savedEnv = $_ENV['SPORA_APP_ENV'] ?? null;
    $_ENV['SPORA_APP_ENV'] = 'production';
    putenv('SPORA_APP_ENV=production');

    try {
        $response = withoutSecretKey(static function (): mixed {
            $kernel = new Kernel();
            return $kernel->handle(Request::create('/api/v1/agents', 'GET'));
        });
    } finally {
        if ($savedEnv !== null) {
            $_ENV['SPORA_APP_ENV'] = $savedEnv;
            putenv("SPORA_APP_ENV={$savedEnv}");
        } else {
            unset($_ENV['SPORA_APP_ENV']);
            putenv('SPORA_APP_ENV');
        }
    }

    $body = json_decode($response->getContent(), true);

    expect($body)->not()->toHaveKey('debug');
});

test('500 response has Content-Type application/json', function (): void {
    $response = withoutSecretKey(static function (): mixed {
        $kernel = new Kernel();
        return $kernel->handle(Request::create('/api/v1/agents', 'GET'));
    });

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

// ---------------------------------------------------------------------------
// 401 Unauthenticated (UnauthenticatedException → Kernel → 401)
// ---------------------------------------------------------------------------

test('UnauthenticatedException from a protected route returns 401 UNAUTHENTICATED', function (): void {
    clearSession();

    // Provide a temporary key so SecurityManager (needed by AgentController) resolves.
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    try {
        $kernel   = new Kernel();
        $response = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
    } finally {
        unset($_ENV['SPORA_SECRET_KEY']);
    }

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
    expect($body['error']['message'])->toBe('Authentication required.');
});

test('401 response has Content-Type application/json', function (): void {
    clearSession();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    try {
        $kernel   = new Kernel();
        $response = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
    } finally {
        unset($_ENV['SPORA_SECRET_KEY']);
    }

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

// ---------------------------------------------------------------------------
// 500 debug mode — exposes exception details when app_env=development
// ---------------------------------------------------------------------------

test('500 response in development mode includes a debug block with exception details', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'development';

    try {
        // Clear secret key so SecurityManager throws → 500, then verify debug block appears.
        $response = withoutSecretKey(static function (): mixed {
            $kernel = new Kernel();
            return $kernel->handle(Request::create('/api/v1/agents', 'GET'));
        });
    } finally {
        unset($_ENV['SPORA_APP_ENV']);
    }

    expect($response->getStatusCode())->toBe(500);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('debug');
    expect($body['debug'])->toHaveKey('exception');
    expect($body['debug'])->toHaveKey('message');
    expect($body['debug'])->toHaveKey('file');
    expect($body['debug'])->toHaveKey('line');
    expect($body['debug']['exception'])->toBeString()->not()->toBeEmpty();
});
