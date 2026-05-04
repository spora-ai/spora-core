<?php

declare(strict_types=1);

use Spora\Http\SseController;

describe('SseController', function (): void {
    it('auth returns 404 when mercure is not configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('sse@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'sse@example.com');

        $controller = new SseController($authService, null, null);
        $response = $controller->auth();

        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('NOT_CONFIGURED');
    });

    it('auth returns hubUrl and token when mercure is configured', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('sse@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'sse@example.com');

        $controller = new SseController($authService, 'http://localhost:3000/.well-known/mercure', 'test-secret-key-for-jwt-signing-32ch');
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
        $userId = $authService->register('sse@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'sse@example.com');
        $secret = 'test-secret-key-for-jwt-signing-32ch';

        $controller = new SseController($authService, 'http://localhost:3000/.well-known/mercure', $secret);
        $response = $controller->auth();

        $body = json_decode($response->getContent(), true);
        $token = $body['token'];
        $parts = explode('.', $token);

        // Decode the payload (middle part)
        $payloadJson = base64_decode(strtr($parts[1] ?? '', '-_', '+/'));
        $payload = json_decode($payloadJson, true);

        expect($payload['mercure']['subscribe'])->toContain('task/*');
        expect($payload['mercure']['subscribe'])->toContain("user/{$userId}/notifications");
    });

    it('auth token expires in 1 hour', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('sse@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'sse@example.com');

        $controller = new SseController($authService, 'http://localhost:3000/.well-known/mercure', 'test-secret-key-for-jwt-signing-32ch');
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
});
