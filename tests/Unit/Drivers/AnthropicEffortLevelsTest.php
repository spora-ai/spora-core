<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The effort levels the Anthropic driver declares, per model.
 *
 * Anthropic publishes which models take `output_config.effort` and which
 * of the five levels each of them takes. A model that does not offer the
 * parameter rejects the request that carries it, and a model that does
 * not offer a level rejects the request that carries that level — so the
 * level set is a property of the model and not of the driver.
 */
function anthropicEffortLevels(string $model): array
{
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
    );

    return array_keys($driver->effortLevels());
}

/**
 * @return array<string, mixed>
 */
function anthropicEffortBody(string $model, ?string $effort): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_effort',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    ))->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        effort: $effort,
    ));

    return $captured;
}

test('the effort levels a Claude model takes are pinned per model', function (string $model, array $expected): void {
    expect(anthropicEffortLevels($model))->toBe($expected);
})->with([
    'the 5.x generation takes every level' => [
        'claude-opus-5-5',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'the 5.x generation takes every level without a minor version' => [
        'claude-opus-5',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'a 5.1 model takes every level' => [
        'claude-fable-5-1',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'a 4.8 model takes every level' => [
        'claude-opus-4-8',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'a 4.7 model takes every level' => [
        'claude-opus-4-7',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'a 4.6 model takes no xhigh' => [
        'claude-opus-4-6',
        ['low', 'medium', 'high', 'max'],
    ],
    'a 4.6 sonnet takes no xhigh' => [
        'claude-sonnet-4-6',
        ['low', 'medium', 'high', 'max'],
    ],
    'opus 4.5 takes an effort but no max' => [
        'claude-opus-4-5-20251101',
        ['low', 'medium', 'high'],
    ],
    'the opus 4.5 alias takes an effort but no max' => [
        'claude-opus-4-5',
        ['low', 'medium', 'high'],
    ],
    'sonnet 4.5 takes no effort at all' => [
        'claude-sonnet-4-5-20250929',
        [],
    ],
    'haiku 4.5 takes no effort at all' => [
        'claude-haiku-4-5-20251001',
        [],
    ],
    'a 3.x model takes no effort at all' => [
        'claude-3-5-sonnet-20241022',
        [],
    ],
    'the oldest 3.x model takes no effort at all' => [
        'claude-3-7-sonnet-20250219',
        [],
    ],
    'an id without a version names the current generation' => [
        'claude-latest',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
    'a compatible endpoint keeps the whole set' => [
        'kimi-k2',
        ['low', 'medium', 'high', 'xhigh', 'max'],
    ],
]);

test('the vendor prefixes and suffixes do not hide the level set', function (): void {
    expect(anthropicEffortLevels('anthropic.claude-opus-4-6-v1:0'))->toBe(['low', 'medium', 'high', 'max'])
        ->and(anthropicEffortLevels('us.anthropic.claude-sonnet-4-5-20250929-v1:0'))->toBe([]);
});

test('a model without an effort sends no effort parameter', function (): void {
    $body = anthropicEffortBody('claude-sonnet-4-5-20250929', 'high');

    expect($body)->not->toHaveKey('output_config');
});

test('a level the model does not take is not sent', function (): void {
    $body = anthropicEffortBody('claude-opus-4-6', 'xhigh');

    expect($body)->not->toHaveKey('output_config');
});

test('a level the model takes is sent', function (): void {
    $body = anthropicEffortBody('claude-opus-4-6', 'max');

    expect($body['output_config'])->toBe(['effort' => 'max']);
});
