<?php

declare(strict_types=1);

use Spora\Http\Exceptions\MercureConfigurationMissingException;
use Spora\Http\SseController;

final class SseControllerTestLiterals
{
    public const SSE_EMAIL = 'sse@example.com';
    public const SSE_PASSWORD = 'Password1!';
    public const SSE_MERCURE_URL = 'http://localhost:3000/.well-known/mercure';
}

describe('SseController', function (): void {
    it('auth returns 404 when mercure is not configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, null, null);
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('auth returns hubUrl and token when mercure is configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key-for-jwt-signing-32ch');
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['hubUrl'])->toBe('/.well-known/mercure');
        expect($body['token'])->not->toBeEmpty();

        // Verify the token is a valid JWT structure (header.payload.signature)
        $parts = explode('.', $body['token']);
        expect(count($parts))->toBe(3);
    });

    it('auth token has correct mercure subscription topics', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);
        $secret = 'test-secret-key-for-jwt-signing-32ch';

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, $secret);
        $response = $controller->auth();

        $body = json_decode($response->getContent(), true);
        $token = $body['token'];
        $parts = explode('.', $token);

        // Decode the payload (middle part)
        $payloadJson = base64_decode(strtr($parts[1] ?? '', '-_', '+/'));
        $payload = json_decode($payloadJson, true);

        expect($payload['mercure']['subscribe'])->toContain("user/{$userId}/tasks");
        expect($payload['mercure']['subscribe'])->toContain("user/{$userId}/notifications");
    });

    it('auth token expires in 1 hour', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key-for-jwt-signing-32ch');
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

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, null);
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('auth returns 404 when only jwtKey is configured (hubUrl missing)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register(SseControllerTestLiterals::SSE_EMAIL, SseControllerTestLiterals::SSE_PASSWORD, 'Sse');
        simulateLoggedInSession($userId, SseControllerTestLiterals::SSE_EMAIL);

        $controller = new SseController($authService, null, 'test-secret-key-for-jwt-signing-32ch');
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });
});

describe('SseController::status', function (): void {
    it('returns active=false when hubUrl is null', function (): void {
        $authService = bootAuthLayer();

        $controller = new SseController($authService, null, null);
        $response = $controller->status();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['active'])->toBeFalse();
        expect($body)->not->toHaveKey('hubUrl');
    });

    it('returns active=true with default hubUrl when hubUrl is configured and publicUrl is null', function (): void {
        $authService = bootAuthLayer();

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key', null);
        $response = $controller->status();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['active'])->toBeTrue();
        expect($body['hubUrl'])->toBe('/.well-known/mercure');
    });

    it('returns active=true with configured publicUrl when publicUrl is provided', function (): void {
        $authService = bootAuthLayer();
        $publicUrl = 'https://mercure.example.com/.well-known/mercure';

        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, 'test-secret-key', $publicUrl);
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
        $controller = new SseController($authService, SseControllerTestLiterals::SSE_MERCURE_URL, null);

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
