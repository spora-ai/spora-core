<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The operator-facing settings reach the wire body: the effort as the
 * nested `reasoning` object, the token cap under the name the API
 * accepts, and the retention toggle as the `store` flag.
 */
function responsesDriverWithBody(array &$captured, string $model = 'gpt-5.5', bool $store = false): OpenAIResponsesDriver
{
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['url'] = $url;
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'ok']],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    return new OpenAIResponsesDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        options: new \Spora\Drivers\Responses\ResponsesDriverOptions(store: $store),
    );
}

function responsesSettingsRequest(?string $effort = null, int $maxTokens = 4096): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        maxTokens: $maxTokens,
        effort: $effort,
    );
}

test('the driver posts to the responses endpoint of the base url', function (): void {
    $captured = [];
    responsesDriverWithBody($captured)->complete(responsesSettingsRequest());

    expect($captured['url'])->toBe('https://api.openai.com/v1/responses');
});

test('the effort reaches the wire as the nested reasoning object', function (): void {
    $captured = [];
    responsesDriverWithBody($captured)->complete(responsesSettingsRequest(effort: 'high'));

    expect($captured['body']['reasoning'])->toBe(['effort' => 'high']);
});

test('the token cap is sent as max_output_tokens and never as max_tokens', function (): void {
    $captured = [];
    responsesDriverWithBody($captured)->complete(responsesSettingsRequest(maxTokens: 2048));

    expect($captured['body'])->toHaveKey('max_output_tokens')
        ->and($captured['body']['max_output_tokens'])->toBe(2048)
        ->and($captured['body'])->not->toHaveKey('max_tokens');
});

test('no effort means no reasoning object on the wire', function (): void {
    $captured = [];
    responsesDriverWithBody($captured)->complete(responsesSettingsRequest());

    expect($captured['body'])->not->toHaveKey('reasoning');
});

test('the store toggle controls the store flag', function (): void {
    $captured = [];
    responsesDriverWithBody($captured, store: true)->complete(responsesSettingsRequest());

    expect($captured['body']['store'])->toBeTrue();
});

test('an untouched store setting means the response is not stored', function (): void {
    $captured = [];
    responsesDriverWithBody($captured)->complete(responsesSettingsRequest());

    expect($captured['body']['store'])->toBeFalse();
});

test('a settings blob without the store key means the response is not stored', function (): void {
    $captured = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = OpenAIResponsesDriver::makeFromSettings([
        'apiKey' => 'test',
        'model' => 'gpt-5.5',
        'baseUrl' => 'https://api.openai.com/v1',
        'httpClient' => $client,
    ], []);

    $driver->complete(responsesSettingsRequest());

    expect($captured['body']['store'])->toBeFalse();
});

test('the store setting arrives as the string the frontend sends', function (): void {
    $captured = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = OpenAIResponsesDriver::makeFromSettings([
        'apiKey' => 'test',
        'model' => 'gpt-5.5',
        'baseUrl' => 'https://api.openai.com/v1',
        'httpClient' => $client,
    ], ['store' => 'true']);

    $driver->complete(responsesSettingsRequest());

    expect($captured['body']['store'])->toBeTrue();
});

test('the driver identifies itself as the openai_responses protocol', function (): void {
    $captured = [];
    $driver = responsesDriverWithBody($captured);

    expect(OpenAIResponsesDriver::getName())->toBe('openai_responses')
        ->and(OpenAIResponsesDriver::getDisplayName())->toBe('OpenAI Responses');
});

test('the pricing tag is the one the operator\'s existing price rows use', function (): void {
    $captured = [];
    $driver = responsesDriverWithBody($captured);

    // The price book is keyed on `(provider, model)`, and the provider
    // is the driver's protocol name. Every OpenAI price row an operator
    // already has is keyed on `openai_compatible`, so this driver has to
    // answer with that: a tag of its own would match no row, and a call
    // with no price row is unpriced — free, with no limits.
    expect($driver->getProviderName())->toBe('openai_compatible');
});

test('the effort levels the driver declares are the options its effort setting offers', function (): void {
    $effortSetting = null;
    foreach (\Spora\Tools\ToolSettingSchema::collect(OpenAIResponsesDriver::class) as $setting) {
        if ($setting->key === 'effort') {
            $effortSetting = $setting;
        }
    }

    expect($effortSetting)->not->toBeNull();

    $optionKeys = array_values(array_filter(
        array_keys($effortSetting->options),
        static fn(string $key): bool => $key !== '',
    ));

    $captured = [];
    $driver = responsesDriverWithBody($captured);

    expect(array_keys($driver->effortLevels()))->toBe($optionKeys);
});
