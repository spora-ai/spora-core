<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Spora\Http\Exceptions\MercureConfigurationMissingException;
use Spora\Http\SseController;
use Symfony\Component\HttpFoundation\Cookie;

describe('SseController', function (): void {
    it('auth returns 404 when mercure is not configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), null, null);
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('authorize returns 404 when mercure is not configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), null, null);
        $response = $controller->authorize();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('auth returns hubUrl and token when mercure is configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key-for-jwt-signing-32ch');
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['hubUrl'])->toBe('/.well-known/mercure');
        expect($body['token'])->not->toBeEmpty();

        // Verify the token is a valid JWT structure (header.payload.signature)
        $parts = explode('.', $body['token']);
        expect(count($parts))->toBe(3);
    });

    it('authorize sets the subscriber cookie scoped to the hub path', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);
        // Materialise the user-principal BEFORE generating the JWT so
        // PrincipalResolver::visiblePrincipalIds has something to return.
        $principalId = createUserPrincipalPublic($userId);

        $controller = new SseController(
            $authService,
            new \Spora\Services\PrincipalResolver(),
            SseControllerTestLiterals::SSE_MERCURE_URL,
            'test-secret-key-for-jwt-signing-32ch',
            '/.well-known/mercure',
            'https://example.com',
        );
        $response = $controller->authorize();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['hubUrl'])->toBe('/.well-known/mercure');
        expect($body['expires'])->toBeGreaterThan(time());

        $cookies = $response->headers->getCookies();
        expect($cookies)->not->toBeEmpty();

        $secure = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === '__Secure-mercure_access_token') {
                $secure = $cookie;
                break;
            }
        }
        expect($secure)->not->toBeNull();
        expect($secure->isHttpOnly())->toBeTrue();
        expect($secure->isSecure())->toBeTrue();
        expect($secure->getPath())->toBe('/.well-known/mercure');
        expect($secure->getSameSite())->toBe(Cookie::SAMESITE_LAX);

        $parts = explode('.', $secure->getValue());
        expect(count($parts))->toBe(3);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $principalId = createUserPrincipalPublic($userId);
        expect($payload['mercure']['subscribe'])->toContain("principal/{$principalId}/tasks");
    });

    it('authorize uses the unprefixed cookie name when app_url is http', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController(
            $authService,
            new \Spora\Services\PrincipalResolver(),
            SseControllerTestLiterals::SSE_MERCURE_URL,
            'test-secret-key-for-jwt-signing-32ch',
            '/.well-known/mercure',
            'http://localhost:8081',
        );
        $response = $controller->authorize();

        $cookie = null;
        foreach ($response->headers->getCookies() as $candidate) {
            if (str_starts_with($candidate->getName(), '__Secure-')) {
                continue;
            }
            $cookie = $candidate;
            break;
        }
        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('mercure_access_token');
        expect($cookie->isSecure())->toBeFalse();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getPath())->toBe('/.well-known/mercure');
        expect($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX);
    });

    it('authorize falls back to mercure_url when only mercure_url is set (no publish_url)', function (): void {
        // The factory wires the same fallback; here we test the path that
        // matters: with hubUrl passed in (mimicking the DI factory using
        // mercure_url as a fallback), authorize returns 200 instead of 404.
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController(
            $authService,
            new \Spora\Services\PrincipalResolver(),
            'https://hub.example.com/.well-known/mercure', // public URL only
            'test-secret-key-for-jwt-signing-32ch',
            '/.well-known/mercure',
            'https://hub.example.com',
        );
        $response = $controller->authorize();

        expect($response->getStatusCode())->toBe(200);
    });

    it('auth token has correct mercure subscription topics', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);
        $secret = 'test-secret-key-for-jwt-signing-32ch';
        // Materialise the user-principal BEFORE generating the JWT so
        // PrincipalResolver::visiblePrincipalIds has something to return.
        $principalId = createUserPrincipalPublic($userId);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, $secret);
        $response = $controller->auth();

        $body = json_decode($response->getContent(), true);
        $token = $body['token'];
        $parts = explode('.', $token);

        // Decode the payload (middle part)
        $payloadJson = base64_decode(strtr($parts[1] ?? '', '-_', '+/'));
        $payload = json_decode($payloadJson, true);

        expect($payload['mercure']['subscribe'])->toContain("principal/{$principalId}/tasks");
        expect($payload['mercure']['subscribe'])->toContain("user/{$userId}/notifications");
    });

    it('auth token expires in 1 hour', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key-for-jwt-signing-32ch');
        $response = $controller->auth();

        $body = json_decode($response->getContent(), true);
        $token = $body['token'];
        $parts = explode('.', $token);
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        $payload = json_decode($payloadJson, true);

        $expectedExp = time() + 3600;
        expect($payload['exp'])->toBeGreaterThanOrEqual($expectedExp - 5);
        expect($payload['exp'])->toBeLessThanOrEqual($expectedExp + 5);
    });

    it('auth returns 404 when only hubUrl is configured (jwtKey missing)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, null);
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('auth returns 404 when only jwtKey is configured (hubUrl missing)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), null, 'test-secret-key-for-jwt-signing-32ch');
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });
});

describe('SseController::status', function (): void {
    it('returns active=false when hubUrl is null', function (): void {
        $authService = bootAuthLayer();

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), null, null);
        $response = $controller->status();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['active'])->toBeFalse();
        expect($body)->not->toHaveKey('hubUrl');
    });

    it('returns active=true with default hubUrl when hubUrl is configured and publicUrl is null', function (): void {
        $authService = bootAuthLayer();

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key', null);
        $response = $controller->status();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['active'])->toBeTrue();
        expect($body['hubUrl'])->toBe('/.well-known/mercure');
    });

    it('returns active=true with configured publicUrl when publicUrl is provided', function (): void {
        $authService = bootAuthLayer();
        $publicUrl = 'https://mercure.example.com/.well-known/mercure';

        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key', $publicUrl);
        $response = $controller->status();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['active'])->toBeTrue();
        expect($body['hubUrl'])->toBe($publicUrl);
    });
});

describe('SseController::generateSubscriberJwt defensive check', function (): void {
    it('throws MercureConfigurationMissingException when jwtKey is null (via reflection)', function (): void {
        $authService = bootAuthLayer();
        $controller = new SseController($authService, new \Spora\Services\PrincipalResolver(), SseControllerTestLiterals::SSE_MERCURE_URL, null);

        $method = new ReflectionMethod($controller, 'generateSubscriberJwt');

        expect(fn() => $method->invoke($controller, 1))
            ->toThrow(MercureConfigurationMissingException::class, 'Mercure JWT key is not configured. Set SPORA_MERCURE_JWT_KEY.');
    });

    it('MercureConfigurationMissingException is a final RuntimeException subclass', function (): void {
        $reflection = new ReflectionClass(MercureConfigurationMissingException::class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isSubclassOf(RuntimeException::class))->toBeTrue();
    });
});
