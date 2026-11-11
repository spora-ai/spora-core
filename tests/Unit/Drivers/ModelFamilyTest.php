<?php

declare(strict_types=1);

use Spora\Drivers\ModelFamily;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The model-name parse.
 *
 * The Claude side is pinned against every current model id, so a new
 * generation has to be classified deliberately. The OpenAI side is
 * pinned against the model enum of the live API spec — every id and the
 * classification it must have — so a new family fails the build instead
 * of being silently mis-classified.
 */

test('the Claude version is parsed from every current model id', function (string $model, ?array $expected): void {
    expect(ModelFamily::claudeVersion($model))->toBe($expected);
})->with([
    'claude-fable-5-1' => ['claude-fable-5-1', [5, 1]],
    'claude-mythos-5-1' => ['claude-mythos-5-1', [5, 1]],
    'claude-fable-5' => ['claude-fable-5', [5, 0]],
    'claude-mythos-5' => ['claude-mythos-5', [5, 0]],
    'claude-mythos-preview' => ['claude-mythos-preview', null],
    'claude-opus-5-5' => ['claude-opus-5-5', [5, 5]],
    'claude-opus-5' => ['claude-opus-5', [5, 0]],
    'claude-opus-4-8' => ['claude-opus-4-8', [4, 8]],
    'claude-opus-4-7' => ['claude-opus-4-7', [4, 7]],
    'claude-opus-4-6' => ['claude-opus-4-6', [4, 6]],
    'claude-opus-4-5-20251101' => ['claude-opus-4-5-20251101', [4, 5]],
    'claude-sonnet-5-5' => ['claude-sonnet-5-5', [5, 5]],
    'claude-sonnet-5' => ['claude-sonnet-5', [5, 0]],
    'claude-sonnet-4-6' => ['claude-sonnet-4-6', [4, 6]],
    'claude-sonnet-4-5-20250929' => ['claude-sonnet-4-5-20250929', [4, 5]],
    'claude-haiku-5-5' => ['claude-haiku-5-5', [5, 5]],
    'claude-haiku-4-5-20251001' => ['claude-haiku-4-5-20251001', [4, 5]],
    'claude-opus-4-1-20250805' => ['claude-opus-4-1-20250805', [4, 1]],
    'claude-opus-4-20250514' => ['claude-opus-4-20250514', [4, 0]],
    'claude-sonnet-4-20250514' => ['claude-sonnet-4-20250514', [4, 0]],
    'claude-3-7-sonnet-20250219' => ['claude-3-7-sonnet-20250219', [3, 7]],
    'claude-3-5-sonnet-20241022' => ['claude-3-5-sonnet-20241022', [3, 5]],
    'claude-3-5-sonnet-20240620' => ['claude-3-5-sonnet-20240620', [3, 5]],
    'claude-3-5-haiku-20241022' => ['claude-3-5-haiku-20241022', [3, 5]],
    'claude-3-opus-20240229' => ['claude-3-opus-20240229', [3, 0]],
    'claude-3-sonnet-20240229' => ['claude-3-sonnet-20240229', [3, 0]],
    'claude-3-haiku-20240307' => ['claude-3-haiku-20240307', [3, 0]],
    'claude-2.1' => ['claude-2.1', [2, 1]],
    'claude-2.0' => ['claude-2.0', [2, 0]],
    'claude-instant-1.2' => ['claude-instant-1.2', [1, 2]],
]);

test('a snapshot date is not read as a minor version', function (): void {
    // `claude-sonnet-4-20250514` is 4.0. Read the date as the minor
    // version and the model would look like 4.20250514 — a generation
    // that does not exist, with adaptive thinking it does not have.
    expect(ModelFamily::claudeVersion('claude-sonnet-4-20250514'))->toBe([4, 0])
        ->and(ModelFamily::claudeSupportsAdaptiveThinking('claude-sonnet-4-20250514'))->toBeFalse();
});

test('a model id without a numeric component is treated as adaptive-capable', function (): void {
    expect(ModelFamily::claudeVersion('claude-mythos-preview'))->toBeNull()
        ->and(ModelFamily::claudeSupportsAdaptiveThinking('claude-mythos-preview'))->toBeTrue();
});

test('the vendor prefixes and suffixes are stripped before the parse', function (): void {
    expect(ModelFamily::claudeVersion('anthropic.claude-opus-4-6-v1:0'))->toBe([4, 6])
        ->and(ModelFamily::claudeVersion('us.anthropic.claude-sonnet-4-5-20250929-v1:0'))->toBe([4, 5])
        ->and(ModelFamily::claudeVersion('claude-haiku-4-5@20251001'))->toBe([4, 5]);
});

test('adaptive thinking starts with the 4.6 generation', function (string $model, bool $expected): void {
    expect(ModelFamily::claudeSupportsAdaptiveThinking($model))->toBe($expected);
})->with([
    'claude-opus-4-6' => ['claude-opus-4-6', true],
    'claude-sonnet-4-6' => ['claude-sonnet-4-6', true],
    'claude-opus-4-7' => ['claude-opus-4-7', true],
    'claude-opus-4-8' => ['claude-opus-4-8', true],
    'claude-opus-5' => ['claude-opus-5', true],
    'claude-sonnet-5-5' => ['claude-sonnet-5-5', true],
    'claude-haiku-5-5' => ['claude-haiku-5-5', true],
    'claude-opus-4-5-20251101' => ['claude-opus-4-5-20251101', false],
    'claude-sonnet-4-5-20250929' => ['claude-sonnet-4-5-20250929', false],
    'claude-haiku-4-5-20251001' => ['claude-haiku-4-5-20251001', false],
    'claude-3-7-sonnet-20250219' => ['claude-3-7-sonnet-20250219', false],
    'claude-3-5-sonnet-20241022' => ['claude-3-5-sonnet-20241022', false],
    'claude-3-5-sonnet-20240620' => ['claude-3-5-sonnet-20240620', false],
]);

test('image input starts with the Claude 3 generation', function (string $model, bool $expected): void {
    expect(ModelFamily::claudeSupportsImageInput($model))->toBe($expected);
})->with([
    'claude-opus-5-5' => ['claude-opus-5-5', true],
    'claude-sonnet-4-6' => ['claude-sonnet-4-6', true],
    'claude-opus-4-5-20251101' => ['claude-opus-4-5-20251101', true],
    'claude-3-opus-20240229' => ['claude-3-opus-20240229', true],
    'claude-3-5-sonnet-20241022' => ['claude-3-5-sonnet-20241022', true],
    'claude-2.1' => ['claude-2.1', false],
    'claude-2.0' => ['claude-2.0', false],
    'claude-instant-1.2' => ['claude-instant-1.2', false],
]);

test('a name that is not a Claude id is not answered for', function (): void {
    expect(ModelFamily::claudeSupportsAdaptiveThinking('gpt-4o'))->toBeFalse()
        ->and(ModelFamily::claudeSupportsImageInput('gpt-4o'))->toBeFalse();
});

test('the image allowlist is the one both OpenAI drivers read', function (string $model, bool $expected): void {
    expect(ModelFamily::openAISupportsImageInput($model))->toBe($expected);
})->with([
    'gpt-4o' => ['gpt-4o', true],
    'gpt-4o-mini' => ['gpt-4o-mini', true],
    'gpt-4-turbo' => ['gpt-4-turbo', true],
    'gpt-4-vision-preview' => ['gpt-4-vision-preview', true],
    'gpt-4.1' => ['gpt-4.1', true],
    'gpt-5.5' => ['gpt-5.5', true],
    'gpt-6-astra' => ['gpt-6-astra', true],
    'o3-mini' => ['o3-mini', true],
    'chatgpt-4o' => ['chatgpt-4o', true],
    'gpt-3.5-turbo' => ['gpt-3.5-turbo', false],
    'gpt-4' => ['gpt-4', false],
    'o1-mini' => ['o1-mini', false],
    'an empty model' => ['', false],
]);

test('both OpenAI drivers answer the image question the same way', function (string $model): void {
    $chat = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
    );

    $responses = new OpenAIResponsesDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
    );

    expect($chat->supportsImageInput())->toBe($responses->supportsImageInput());
})->with(['gpt-4o', 'gpt-4', 'gpt-5.5', 'o1-mini', 'gpt-3.5-turbo']);

/**
 * The model enum of the live OpenAI API spec
 * (`components.schemas.ModelIds`: ModelIdsShared + ModelIdsResponses),
 * with the classification the reasoning-model rule must produce for it.
 */
function openAiModelEnum(): array
{
    $path = __DIR__ . '/fixtures/openai-model-ids.json';
    $raw = file_get_contents($path);
    expect($raw)->toBeString();

    /** @var array<string, bool|string> $decoded */
    $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    $enum = [];
    foreach ($decoded as $model => $isReasoning) {
        if (str_starts_with($model, '_')) {
            continue;
        }
        expect($isReasoning)->toBeBool();
        $enum[$model] = $isReasoning;
    }

    return $enum;
}

test('the reasoning-model rule classifies every model in the OpenAI enum', function (string $model, bool $isReasoning): void {
    expect(ModelFamily::isOpenAIReasoningModel($model))->toBe($isReasoning);
})->with(function (): array {
    $cases = [];
    foreach (openAiModelEnum() as $model => $isReasoning) {
        $cases[$model] = [$model, $isReasoning];
    }
    return $cases;
});

test('the reasoning-model rule ignores the chat-latest and gpt-oss ids', function (): void {
    expect(ModelFamily::isOpenAIReasoningModel('gpt-4o'))->toBeFalse()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-5-chat-latest'))->toBeFalse()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-5.3-chat-latest'))->toBeFalse()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-oss'))->toBeFalse()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-oss-120b'))->toBeFalse()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-oss-20b'))->toBeFalse();
});

test('the reasoning-model rule accepts the reasoning families', function (): void {
    expect(ModelFamily::isOpenAIReasoningModel('gpt-5'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-5.1'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-5.5-2026-04-23'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-6'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('gpt-6.1-sol'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('o1'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('o1-mini'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('o3'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('o4-mini'))->toBeTrue();
});

test('a gateway prefix does not hide the reasoning family', function (): void {
    expect(ModelFamily::isOpenAIReasoningModel('openai/gpt-5.5'))->toBeTrue()
        ->and(ModelFamily::isOpenAIReasoningModel('openai/gpt-4o'))->toBeFalse();
});

test('only the GPT-5.4 generation and later restrict tools to the none effort', function (): void {
    expect(ModelFamily::openAIRequiresNoneEffortForTools('gpt-5.4'))->toBeTrue()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-5.5'))->toBeTrue()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-6.1-sol'))->toBeTrue()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-5.2'))->toBeFalse()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-5.1'))->toBeFalse()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-5'))->toBeFalse()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('o3-mini'))->toBeFalse()
        ->and(ModelFamily::openAIRequiresNoneEffortForTools('gpt-4o'))->toBeFalse();
});
