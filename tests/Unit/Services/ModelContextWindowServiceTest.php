<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\ModelContextWindowService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

describe('ModelContextWindowService::getContextWindow', function (): void {

    it('returns null for non-OpenAI driver', function (): void {
        $http = new MockHttpClient();
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(AnthropicCompatibleDriver::class, 'claude-3', 'key', 'https://api.example.com'))->toBeNull();
    });

    it('returns the context_window field from the OpenAI API response', function (): void {
        $http = new MockHttpClient(new MockResponse(json_encode(['context_window' => 128000]), ['http_code' => 200]));
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'key', 'https://api.example.com'))->toBe(128000);
    });

    it('falls back to max_tokens when context_window is missing', function (): void {
        $http = new MockHttpClient(new MockResponse(json_encode(['max_tokens' => 8192]), ['http_code' => 200]));
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'key', 'https://api.example.com'))->toBe(8192);
    });

    it('returns null when the API returns 404', function (): void {
        $http = new MockHttpClient(new MockResponse('not found', ['http_code' => 404]));
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'key', 'https://api.example.com'))->toBeNull();
    });

    it('returns null when the response body has no context_window or max_tokens', function (): void {
        $http = new MockHttpClient(new MockResponse(json_encode(['foo' => 'bar']), ['http_code' => 200]));
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'key', 'https://api.example.com'))->toBeNull();
    });

    it('returns null when the response is not valid JSON', function (): void {
        $http = new MockHttpClient(new MockResponse('not json', ['http_code' => 200]));
        $svc = new ModelContextWindowService($http);
        expect($svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'key', 'https://api.example.com'))->toBeNull();
    });

    it('skips the Authorization header when apiKey is empty', function (): void {
        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            $headers = $options['headers'] ?? [];
            expect($headers)->not->toHaveKey('Authorization');
            return new MockResponse(json_encode(['context_window' => 4096]), ['http_code' => 200]);
        });
        $svc = new ModelContextWindowService($http);
        $result = $svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', '', 'https://api.example.com');
        expect($result)->toBe(4096);
    });

    it('constructs the correct URL with baseUrl + /models/{model}', function (): void {
        $capturedUrl = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return new MockResponse(json_encode(['context_window' => 1000]), ['http_code' => 200]);
        });
        $svc = new ModelContextWindowService($http);
        $svc->getContextWindow(OpenAICompatibleDriver::class, 'gpt-4o', 'k', 'https://api.example.com/v1/');
        expect($capturedUrl)->toBe('https://api.example.com/v1/models/gpt-4o');
    });
});
