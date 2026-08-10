<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use ReflectionClass;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\AnthropicDriverOptions;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * B2 — Anthropic driver settings reach the wire body and headers.
 * One assertion per UI-editable setting so a future refactor that drops
 * a field is caught immediately.
 */
function anthropicTestRequest(?float $temperature = null, ?int $maxTokens = null, string $systemPrompt = 'You are helpful.'): LLMRequest
{
    return new LLMRequest(
        systemPrompt: $systemPrompt,
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        maxTokens: $maxTokens ?? 4096,
        temperature: $temperature ?? 0.7,
    );
}

function anthropicMockResponse(): MockResponse
{
    return new MockResponse(json_encode([
        'id'          => 'msg_test',
        'stop_reason' => 'end_turn',
        'content'     => [['type' => 'text', 'text' => 'ok']],
        'usage'       => ['input_tokens' => 5, 'output_tokens' => 1],
    ]), ['http_code' => 200]);
}

function captureAnthropicRequestBody(array &$captured): callable
{
    return static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['url']    = $url;
        $captured['body']   = json_decode($options['body'], true);
        $captured['header'] = $options['headers'] ?? [];
        return anthropicMockResponse();
    };
}

// api_key → x-api-key header

test('Anthropic driver sets x-api-key header from api_key setting', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'sk-ant-test-1234',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    $headers = $captured['header'];
    $xKey = array_values(array_filter($headers, static fn($h) => str_starts_with($h, 'x-api-key:')));
    expect($xKey)->not->toBeEmpty();
    expect($xKey[0])->toContain('x-api-key: sk-ant-test-1234');
});

test('Anthropic driver omits x-api-key header when api_key is empty', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: '',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    $headers = $captured['header'];
    $xKey = array_filter($headers, static fn($h) => str_starts_with($h, 'x-api-key:'));
    expect($xKey)->toBeEmpty();
});

test('Anthropic driver includes anthropic-version header', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    $headers = $captured['header'];
    $version = array_filter($headers, static fn($h) => str_starts_with($h, 'anthropic-version:'));
    expect($version)->not->toBeEmpty();
});

// base_url

test('Anthropic driver appends /v1/messages to base_url', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://my-proxy.example.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    expect($captured['url'])->toBe('https://my-proxy.example.com/v1/messages');
});

// model

test('Anthropic driver passes the configured model verbatim in body', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-7-sonnet-20250219',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    expect($captured['body']['model'])->toBe('claude-3-7-sonnet-20250219');
});

// temperature (now reads from LLMRequest — refactor)

test('Anthropic driver reads temperature from LLMRequest, not AnthropicDriverOptions', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
        options: new AnthropicDriverOptions(/* no temperature */),
    );
    $driver->complete(anthropicTestRequest(temperature: 0.3));

    expect($captured['body']['temperature'])->toBe(0.3);
});

test('Anthropic driver emits temperature=0.0 verbatim (deterministic mode)', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest(temperature: 0.0));

    expect($captured['body']['temperature'])->toBe(0.0);
});

// max_tokens

test('Anthropic driver passes LLMRequest maxTokens into max_tokens body field', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest(maxTokens: 16384));

    expect($captured['body']['max_tokens'])->toBe(16384);
});

// thinking_budget

test('Anthropic driver includes thinking block when thinkingBudget is set', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-7-sonnet-20250219',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
        options: new AnthropicDriverOptions(thinkingBudget: 2048),
    );
    $driver->complete(anthropicTestRequest());

    expect($captured['body']['thinking'])->toBe([
        'type'         => 'enabled',
        'budget_tokens' => 2048,
    ]);
});

test('Anthropic driver omits thinking block when thinkingBudget is null', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest());

    expect($captured['body'])->not->toHaveKey('thinking');
});

// enable_prompt_caching

test('Anthropic driver wraps system as cache_control array when enablePromptCaching=true (default)', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest(systemPrompt: 'cached system prompt'));

    expect($captured['body']['system'])->toBeArray();
    expect($captured['body']['system'][0])->toMatchArray([
        'type' => 'text',
        'text' => 'cached system prompt',
        'cache_control' => ['type' => 'ephemeral'],
    ]);
});

test('Anthropic driver passes system as a plain string when enablePromptCaching=false', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
        options: new AnthropicDriverOptions(enablePromptCaching: false),
    );
    $driver->complete(anthropicTestRequest(systemPrompt: 'no cache here'));

    expect($captured['body']['system'])->toBe('no cache here');
});

// timeout

test('Anthropic driver applies the configured timeout', function (): void {
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        timeout: 99,
    );

    $reflection = new ReflectionClass($driver);
    $prop = $reflection->getProperty('timeout');
    $prop->setAccessible(true);

    expect($prop->getValue($driver))->toBe(99);
});

// supports_image_input

test('Anthropic driver respects explicit supportsImageInput override on a non-vision model', function (): void {
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-2.0',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        options: new AnthropicDriverOptions(supportsImageInput: true),
    );

    expect($driver->supportsImageInput())->toBeTrue();
});

// request shape

test('Anthropic driver includes model, system, messages, max_tokens, temperature in body', function (): void {
    $captured = [];
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(captureAnthropicRequestBody($captured)),
    );
    $driver->complete(anthropicTestRequest(maxTokens: 2048, temperature: 0.5));

    expect($captured['body'])->toHaveKeys(['model', 'system', 'messages', 'max_tokens', 'temperature']);
    expect($captured['body']['max_tokens'])->toBe(2048);
    expect($captured['body']['temperature'])->toBe(0.5);
    expect($captured['body']['messages'][0]['role'])->toBe('user');
});
