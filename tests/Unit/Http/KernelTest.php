<?php

declare(strict_types=1);

/* namespace removed */

use DI\Container;
use Spora\Core\Kernel;
use Spora\Services\AgentServiceInterface;
use Symfony\Component\HttpFoundation\Request;

afterEach(function (): void {
    unset($_ENV['SPORA_SECRET_KEY']);
    $_SESSION = [];
});

test('Kernel instantiates without throwing', function (): void {
    expect(fn() => new Kernel())->not()->toThrow(Throwable::class);
});

test('getContainer() returns a DI Container instance', function (): void {
    $kernel = new Kernel();

    expect($kernel->getContainer())->toBeInstanceOf(Container::class);

    unset($kernel);
    gc_collect_cycles();
});

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

    unset($kernel);
    gc_collect_cycles();
});

test('POST to unknown route returns 404 JSON', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/nonexistent', 'POST');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(404);

    unset($kernel);
    gc_collect_cycles();
});

test('404 response has Content-Type application/json', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/nope', 'GET'));

    expect($response->headers->get('Content-Type'))->toContain('application/json');

    unset($kernel);
    gc_collect_cycles();
});

test('wrong HTTP method on known route returns 405 JSON with METHOD_NOT_ALLOWED code', function (): void {
    $kernel  = new Kernel();
    // /api/v1/auth/login only accepts POST
    $request  = Request::create('/api/v1/auth/login', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(405);

    $body = json_decode($response->getContent(), true);

    expect($body['error']['code'])->toBe('METHOD_NOT_ALLOWED');

    unset($kernel);
    gc_collect_cycles();
});

test('405 response body contains a message string', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/api/v1/auth/login', 'GET'));
    $body     = json_decode($response->getContent(), true);

    expect($body['error'])->toHaveKey('message');
    expect($body['error']['message'])->toBeString()->not()->toBeEmpty();

    unset($kernel);
    gc_collect_cycles();
});

test('405 response has Content-Type application/json', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/api/v1/auth/login', 'DELETE'));

    expect($response->headers->get('Content-Type'))->toContain('application/json');

    unset($kernel);
    gc_collect_cycles();
});

test('valid route with correct method dispatches to controller and returns a response', function (): void {
    $kernel   = new Kernel();
    // POST /api/v1/auth/login is routed to AuthController::login (stub returns 501)
    $request  = Request::create('/api/v1/auth/login', 'POST');
    $response = $kernel->handle($request);

    // Must not be a routing error - controller was reached
    expect($response->getStatusCode())->not()->toBe(404);
    expect($response->getStatusCode())->not()->toBe(405);

    unset($kernel);
    gc_collect_cycles();
});

test('dispatched stub controller returns JSON with error envelope', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/login', 'POST');
    $response = $kernel->handle($request);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error'])->toHaveKey('code');

    unset($kernel);
    gc_collect_cycles();
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
    $authService = bootAuthLayer();
    $userId = $authService->register('kernel500@example.com', 'Password1!', 'Kernel Test');
    $authService->login('kernel500@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $container = $kernel->getContainer();

    $mockAgentService = Mockery::mock(AgentServiceInterface::class);
    $mockAgentService->shouldReceive('getAgentsForUser')->andThrow(new RuntimeException('Controller error'));
    $container->set(AgentServiceInterface::class, $mockAgentService);

    $request = Request::create('/api/v1/agents', 'GET', [], [], [], [
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(500);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error']['code'])->toBe('INTERNAL_SERVER_ERROR');
    expect($body['error'])->toHaveKey('message');
});

test('500 response in production mode does not expose exception details', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'production';
    putenv('SPORA_APP_ENV=production');

    $authService = bootAuthLayer();
    $userId = $authService->register('kernel500prod@example.com', 'Password1!', 'Kernel Test');
    $authService->login('kernel500prod@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    try {
        $kernel = new Kernel();
        $container = $kernel->getContainer();

        $mockAgentService = Mockery::mock(AgentServiceInterface::class);
        $mockAgentService->shouldReceive('getAgentsForUser')->andThrow(new RuntimeException('Controller error'));
        $container->set(AgentServiceInterface::class, $mockAgentService);

        $request = Request::create('/api/v1/agents', 'GET', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ]);
        $response = $kernel->handle($request);
        $kernel->__destruct();
    } finally {
        unset($_ENV['SPORA_APP_ENV']);
        putenv('SPORA_APP_ENV');
    }

    $body = json_decode($response->getContent(), true);

    expect($body)->not()->toHaveKey('debug');
});

test('500 response has Content-Type application/json', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('kernel500ct@example.com', 'Password1!', 'Kernel Test');
    $authService->login('kernel500ct@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $container = $kernel->getContainer();

    $mockAgentService = Mockery::mock(AgentServiceInterface::class);
    $mockAgentService->shouldReceive('getAgentsForUser')->andThrow(new RuntimeException('Controller error'));
    $container->set(AgentServiceInterface::class, $mockAgentService);

    $request = Request::create('/api/v1/agents', 'GET', [], [], [], [
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

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

    $kernel->__destruct();
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

    $kernel->__destruct();
});

test('500 response in development mode includes a debug block with exception details', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'development';

    $authService = bootAuthLayer();
    $userId = $authService->register('kernel500dev@example.com', 'Password1!', 'Kernel Test');
    $authService->login('kernel500dev@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    try {
        $kernel = new Kernel();
        $container = $kernel->getContainer();

        $mockAgentService = Mockery::mock(AgentServiceInterface::class);
        $mockAgentService->shouldReceive('getAgentsForUser')->andThrow(new RuntimeException('Controller error'));
        $container->set(AgentServiceInterface::class, $mockAgentService);

        $request = Request::create('/api/v1/agents', 'GET', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ]);
        $response = $kernel->handle($request);
        $kernel->__destruct();
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

test('deprecation warnings are logged to Monolog and not output to screen', function (): void {
    $_ENV['SPORA_APP_ENV'] = 'production';
    $_ENV['SPORA_LOG_LEVEL'] = 'debug';

    // Use a temp file for the log so we can assert on it.
    $tmpLog = sys_get_temp_dir() . '/spora_deprecation_test_' . uniqid();
    $_ENV['SPORA_LOG_PATH'] = $tmpLog;

    $kernel = new Kernel();
    try {
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
    } finally {
        $kernel->__destruct();
        unset($_ENV['SPORA_APP_ENV'], $_ENV['SPORA_LOG_LEVEL'], $_ENV['SPORA_LOG_PATH']);
        @unlink($tmpLog);
    }
});

test('log stdout configures Monolog to write to stdout', function (): void {
    $_ENV['SPORA_LOG_PATH'] = 'stdout';

    $kernel = new Kernel();
    try {
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
        $kernel->__destruct();
        unset($_ENV['SPORA_LOG_PATH']);
    }
});

test('public route with no middleware works without session or CSRF', function (): void {
    $kernel = new Kernel();

    // /api/v1/config has no middleware — should succeed even without auth
    $request = Request::create('/api/v1/config', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('allow_registration');

    $kernel->__destruct();
});

test('protected route without session returns 401 UNAUTHENTICATED', function (): void {
    clearSession();

    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    $kernel = new Kernel();
    $request = Request::create('/api/v1/agents', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
    expect($body['error']['message'])->toBe('Authentication required.');

    $kernel->__destruct();
});

test('protected route with valid session but no CSRF token returns 403 CSRF_TOKEN_MISSING', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('mw_nocsrf@example.com', 'Password1!', 'MW Test');
    $authService->login('mw_nocsrf@example.com', 'Password1!');

    // No CSRF token in session
    unset($_SESSION['csrf_token']);

    $kernel = new Kernel();
    // POST /api/v1/agents requires CSRF token (POST is not a safe method)
    $request = Request::create('/api/v1/agents', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        // No X-CSRF-Token header
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // AuthMiddleware passes (session is valid), CsrfMiddleware blocks (no token) → 403
    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
});

test('protected route with valid session and valid CSRF token succeeds', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('mw_success@example.com', 'Password1!', 'MW Test');
    $authService->login('mw_success@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $container = $kernel->getContainer();

    // Mock AgentService so the controller returns predictable data
    $mockAgentService = Mockery::mock(AgentServiceInterface::class);
    $mockAgentService->shouldReceive('getAgentsForUser')->andReturn([]);
    $container->set(AgentServiceInterface::class, $mockAgentService);

    $request = Request::create('/api/v1/agents', 'GET', [], [], [], [
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // Both middleware pass, controller is reached → 200
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('data');
    expect($body['data'])->toHaveKey('agents');
    expect($body['data']['agents'])->toBeArray();
});

test('csrf-only route without CSRF token returns 403 CSRF_TOKEN_MISSING', function (): void {
    // /api/v1/auth/logout has CsrfMiddleware only (no AuthMiddleware)
    // The session does not need to be valid for CsrfMiddleware to run
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $kernel = new Kernel();
    $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        // No X-CSRF-Token header
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
});

test('csrf-only route with valid CSRF token passes through to controller', function (): void {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // CsrfMiddleware passes, controller is reached (returns its own response)
    expect($response->getStatusCode())->not()->toBe(403);
    expect($response->getStatusCode())->not()->toBe(401);
});

test('protected route with wrong HTTP method returns 401 when not authenticated', function (): void {
    clearSession();

    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    $kernel = new Kernel();
    // POST to a GET-only route — but AuthMiddleware blocks first (no session) → 401
    $request = Request::create('/api/v1/agents', 'POST');
    $response = $kernel->handle($request);

    // Middleware runs before HTTP method check, so auth is checked first
    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');

    $kernel->__destruct();
});

test('parameterized protected route GET /api/v1/agents/{id} without session returns 401', function (): void {
    clearSession();

    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    $kernel = new Kernel();
    // Request to /api/v1/agents/123 (route has {id} parameter)
    $request = Request::create('/api/v1/agents/123', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
    $kernel->__destruct();
});

test('parameterized protected route DELETE /api/v1/agents/{id} without session returns 401', function (): void {
    clearSession();

    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    $kernel = new Kernel();
    // Request to /api/v1/agents/456 (route has {id} parameter)
    $request = Request::create('/api/v1/agents/456', 'DELETE');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');

    $kernel->__destruct();
});

test('parameterized protected route PATCH /api/v1/agents/{id} with valid auth but no CSRF returns 403', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('param_nocsrf@example.com', 'Password1!', 'Param Test');
    $authService->login('param_nocsrf@example.com', 'Password1!');

    // No CSRF token in session
    unset($_SESSION['csrf_token']);

    $kernel = new Kernel();

    // Request to /api/v1/agents/123 (route has {id} parameter) using PATCH (not safe)
    $request = Request::create('/api/v1/agents/123', 'PATCH', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        // No X-CSRF-Token header
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // AuthMiddleware passes (session is valid), CsrfMiddleware blocks (no token) → 403
    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
});

test('parameterized protected route GET /api/v1/agents/{id} with valid auth and CSRF passes through middleware', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('param_success@example.com', 'Password1!', 'Param Success');
    $authService->login('param_success@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $container = $kernel->getContainer();

    // Mock AgentService so the controller doesn't throw when checking for null
    $mockAgentService = Mockery::mock(AgentServiceInterface::class);
    $mockAgentService->shouldReceive('getAgent')->andReturn(null);
    $container->set(AgentServiceInterface::class, $mockAgentService);

    // Request to /api/v1/agents/123 (route has {id} parameter)
    $request = Request::create('/api/v1/agents/123', 'GET', [], [], [], [
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // Both middleware pass, controller is reached (returns 404 because agent doesn't exist, not 401/403)
    expect($response->getStatusCode())->not()->toBe(401);
    expect($response->getStatusCode())->not()->toBe(403);
});

test('parameterized protected route DELETE /api/v1/agents/{id} with valid auth and CSRF passes through middleware', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('param_delete@example.com', 'Password1!', 'Param Delete');
    $authService->login('param_delete@example.com', 'Password1!');

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel = new Kernel();
    $container = $kernel->getContainer();

    // Mock AgentService
    $mockAgentService = Mockery::mock(AgentServiceInterface::class);
    $mockAgentService->shouldReceive('deleteAgent')->andReturn(true);
    $container->set(AgentServiceInterface::class, $mockAgentService);

    // Request to /api/v1/agents/789 (route has {id} parameter)
    $request = Request::create('/api/v1/agents/789', 'DELETE', [], [], [], [
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ]);
    $response = $kernel->handle($request);
    $kernel->__destruct();

    // Both middleware pass, controller is reached (not blocked by 401 or 403)
    expect($response->getStatusCode())->not()->toBe(401);
    expect($response->getStatusCode())->not()->toBe(403);
});

test('unknown route returns 404 even when unauthenticated', function (): void {
    clearSession();

    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));

    $kernel = new Kernel();
    $request = Request::create('/api/v1/nonexistent-route', 'GET');
    $response = $kernel->handle($request);

    // Not a 401 because the route doesn't exist — 404 takes priority
    expect($response->getStatusCode())->toBe(404);

    $kernel->__destruct();
});
