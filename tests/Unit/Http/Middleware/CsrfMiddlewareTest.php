<?php

declare(strict_types=1);

use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Security\CsrfTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
    clearSession();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

function csrfNext(Request $request, int $status = 200, array $body = ['ok' => true]): Response
{
    return new JsonResponse($body, $status);
}

test('handle() calls $next() for GET requests without checking CSRF', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $called = false;
    $response = $middleware->handle(
        Request::create('/foo', 'GET'),
        function () use (&$called): Response {
            $called = true;
            return csrfNext(Request::create('/foo', 'GET'), 200, ['passed' => true]);
        },
    );

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true)['passed'])->toBeTrue();
});

test('handle() calls $next() for HEAD requests without checking CSRF', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $called = false;
    $response = $middleware->handle(
        Request::create('/foo', 'HEAD'),
        function () use (&$called): Response {
            $called = true;
            return new Response('', 200);
        },
    );

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('handle() calls $next() for OPTIONS requests without checking CSRF', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $called = false;
    $response = $middleware->handle(
        Request::create('/foo', 'OPTIONS'),
        function () use (&$called): Response {
            $called = true;
            return new Response('', 204);
        },
    );

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(204);
});

test('handle() returns 403 CSRF_TOKEN_MISSING for POST without X-CSRF-Token header', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $called = false;
    $response = $middleware->handle(
        Request::create('/foo', 'POST'),
        function () use (&$called): Response {
            $called = true;
            return csrfNext(Request::create('/foo', 'POST'));
        },
    );

    expect($called)->toBeFalse();
    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
});

test('handle() returns 403 CSRF_TOKEN_MISSING when X-CSRF-Token is empty string', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());
    $request = Request::create('/foo', 'POST');
    $request->headers->set('X-CSRF-Token', '');

    $called = false;
    $response = $middleware->handle(
        $request,
        function () use (&$called, $request): Response {
            $called = true;
            return csrfNext($request);
        },
    );

    expect($called)->toBeFalse();
    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
});

test('handle() returns 403 CSRF_INVALID for POST with token that does not match session', function (): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $request = Request::create('/foo', 'POST');
    $request->headers->set('X-CSRF-Token', 'totally-wrong-token');

    $called = false;
    $response = $middleware->handle(
        $request,
        function () use (&$called, $request): Response {
            $called = true;
            return csrfNext($request);
        },
    );

    expect($called)->toBeFalse();
    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_INVALID');
});

test('handle() returns 403 CSRF_INVALID for POST when session has no token at all', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $request = Request::create('/foo', 'POST');
    $request->headers->set('X-CSRF-Token', 'any-token-here');

    $called = false;
    $response = $middleware->handle(
        $request,
        function () use (&$called, $request): Response {
            $called = true;
            return csrfNext($request);
        },
    );

    expect($called)->toBeFalse();
    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('CSRF_INVALID');
});

test('handle() calls $next() for POST when X-CSRF-Token matches session', function (): void {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    $request = Request::create('/foo', 'POST');
    $request->headers->set('X-CSRF-Token', $token);

    $called = false;
    $response = $middleware->handle(
        $request,
        function () use (&$called, $request): Response {
            $called = true;
            return csrfNext($request, 201, ['created' => true]);
        },
    );

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(201);
    expect(json_decode($response->getContent(), true)['created'])->toBeTrue();
});

test('handle() enforces CSRF for PUT, PATCH, DELETE methods', function (): void {
    $middleware = new CsrfMiddleware(new CsrfTokenService());

    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        $request = Request::create('/foo', $method);

        $called = false;
        $response = $middleware->handle(
            $request,
            function () use (&$called, $request): Response {
                $called = true;
                return csrfNext($request);
            },
        );

        expect($called)->toBeFalse();
        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('CSRF_TOKEN_MISSING');
    }
});
