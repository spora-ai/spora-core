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

test('uncaught controller exception returns 500 JSON', function (): void {
    $kernel = new Kernel();
    // RecipeController::index() throws RuntimeException — perfect 500 fixture
    $request  = Request::create('/api/v1/recipes', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(500);

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('error');
    expect($body['error']['code'])->toBe('INTERNAL_SERVER_ERROR');
    expect($body['error'])->toHaveKey('message');
});

test('500 response in production mode does not expose exception details', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/recipes', 'GET');
    $response = $kernel->handle($request);

    $body = json_decode($response->getContent(), true);

    // config.php has app_env=production — debug block must be absent
    expect($body)->not()->toHaveKey('debug');
});

test('500 response has Content-Type application/json', function (): void {
    $kernel   = new Kernel();
    $response = $kernel->handle(Request::create('/api/v1/recipes', 'GET'));

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
        $response = $kernel->handle(Request::create('/api/v1/agent', 'GET'));
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
        $response = $kernel->handle(Request::create('/api/v1/agent', 'GET'));
    } finally {
        unset($_ENV['SPORA_SECRET_KEY']);
    }

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});
