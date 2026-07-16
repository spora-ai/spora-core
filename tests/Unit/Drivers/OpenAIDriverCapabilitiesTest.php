<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\OpenAICompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Plan §12 B2b — OpenAICompatibleDriver capability pinning.
 */
function makeOpenAI(string $model): OpenAICompatibleDriver
{
    return new OpenAICompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
}

test('gpt-4o* supports image input', function (): void {
    expect(makeOpenAI('gpt-4o')->supportsImageInput())->toBeTrue();
    expect(makeOpenAI('gpt-4o-mini')->supportsImageInput())->toBeTrue();
});

test('gpt-4-vision* supports image input', function (): void {
    expect(makeOpenAI('gpt-4-vision-preview')->supportsImageInput())->toBeTrue();
});

test('gpt-4-turbo supports image input (M-minor-9 fix)', function (): void {
    expect(makeOpenAI('gpt-4-turbo')->supportsImageInput())->toBeTrue();
    expect(makeOpenAI('gpt-4-turbo-2024-04-09')->supportsImageInput())->toBeTrue();
});

test('o1* supports image input', function (): void {
    expect(makeOpenAI('o1-preview')->supportsImageInput())->toBeTrue();
    expect(makeOpenAI('o1-mini')->supportsImageInput())->toBeFalse(); // explicitly excluded
    expect(makeOpenAI('o1-pro')->supportsImageInput())->toBeTrue();
});

test('o3* supports image input', function (): void {
    expect(makeOpenAI('o3-mini')->supportsImageInput())->toBeTrue();
    expect(makeOpenAI('o3')->supportsImageInput())->toBeTrue();
});

test('gpt-3.5* does NOT support image input', function (): void {
    expect(makeOpenAI('gpt-3.5-turbo')->supportsImageInput())->toBeFalse();
});

test('plain gpt-4 does NOT support image input', function (): void {
    expect(makeOpenAI('gpt-4')->supportsImageInput())->toBeFalse();
});
