<?php

declare(strict_types=1);

/* namespace removed */

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

    // Must not be a routing error - controller was reached
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
    $response = withoutSecretKey(static function (): mixed {
        $kernel = new Kernel();
        $res = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
            $kernel->__destruct();
            return $res;
        });

    expect($response->getStatusCode())->toBe(500);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error']['code'])->toBe('INTERNAL_SERVER_ERROR');
    expect($body['error'])->toHaveKey('message');
});

test('500 response in production mode does not expose exception details', function (): void {
    $savedEnv = $_ENV['SPORA_APP_ENV'] ?? null;
    $_ENV['SPORA_APP_ENV'] = 'production';
    putenv('SPORA_APP_ENV=production');

    try {
        $response = withoutSecretKey(static function (): mixed {
            $kernel = new Kernel();
            $res = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
            $kernel->__destruct();
            return $res;
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
        $res = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
            $kernel->__destruct();
            return $res;
        });

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

// ---------------------------------------------------------------------------
// 401 Unauthenticated (UnauthenticatedException -> Kernel -> 401)
// ---------------------------------------------------------------------------

test('UnauthenticatedException from a protected route returns 401 UNAUTHENTICATED', function (): void {
    clearSession();

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
// 500 debug mode - exposes exception details when app_env=development
// ---------------------------------------------------------------------------

test('500 response in development mode includes a debug block with exception details', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'development';

    try {
        $response = withoutSecretKey(static function (): mixed {
            $kernel = new Kernel();
            $res = $kernel->handle(Request::create('/api/v1/agents', 'GET'));
            $kernel->__destruct();
            return $res;
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

// ---------------------------------------------------------------------------
// Error handling - vendor deprecations captured by set_error_handler
// ---------------------------------------------------------------------------

test('deprecation warnings are logged to Monolog and not output to screen', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'production';
    $_ENV['SPORA_LOG_LEVEL'] = 'debug';

    // Use a temp file for the log so we can assert on it.
    $tmpLog = sys_get_temp_dir() . '/spora_deprecation_test_' . uniqid();
    $_ENV['SPORA_LOG_PATH'] = $tmpLog;

    try {
        $kernel = new Kernel();
        $container = $kernel->getContainer();
        $logger = $container->get(Psr\Log\LoggerInterface::class);

        // Clear any existing handlers and add one that writes to our temp file.
        $logger->setHandlers([
            new Monolog\Handler\StreamHandler($tmpLog, Monolog\Level::Debug),
        ]);

        // Trigger a user deprecation - should be captured by the error handler.
        trigger_error('Test deprecation message', E_USER_DEPRECATED);

        // The deprecation should be in the log file.
        $logContents = file_get_contents($tmpLog);

        expect($logContents)->toContain('Test deprecation message');
        // E_USER_DEPRECATED = 16384
        expect($logContents)->toContain('16384');
        unset($kernel);
    } finally {
        unset($_ENV['SPORA_APP_ENV'], $_ENV['SPORA_LOG_LEVEL'], $_ENV['SPORA_LOG_PATH']);
        @unlink($tmpLog);
    }
});

test('log stdout configures Monolog to write to stdout', function (): void {
    $_ENV['SPORA_LOG_PATH'] = 'stdout';

    try {
        $kernel = new Kernel();
        $container = $kernel->getContainer();
        $logger = $container->get(Psr\Log\LoggerInterface::class);

        $handlers = $logger->getHandlers();
        expect($handlers)->not()->toBeEmpty();

        $handler = $handlers[0];
        expect($handler)->toBeInstanceOf(Monolog\Handler\StreamHandler::class);

        // StreamHandler stores the stream URL in a private $url property.
        $reflection = new ReflectionProperty($handler, 'url');
        expect($reflection->getValue($handler))->toBe('php://stdout');
    } finally {
        unset($_ENV['SPORA_LOG_PATH']);
    }
});
