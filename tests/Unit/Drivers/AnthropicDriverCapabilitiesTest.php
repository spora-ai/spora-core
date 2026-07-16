<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Plan §12 B2b — AnthropicCompatibleDriver capability pinning.
 */
function makeAnthropic(string $model): AnthropicCompatibleDriver
{
    return new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
}

test('claude-3-opus-* supports image input', function (): void {
    expect(makeAnthropic('claude-3-opus-20240229')->supportsImageInput())->toBeTrue();
});

test('claude-3-5-sonnet-* supports image input', function (): void {
    expect(makeAnthropic('claude-3-5-sonnet-20241022')->supportsImageInput())->toBeTrue();
});

test('claude-4-* supports image input', function (): void {
    expect(makeAnthropic('claude-4-sonnet-20250514')->supportsImageInput())->toBeTrue();
});

test('claude-2* does NOT support image input', function (): void {
    expect(makeAnthropic('claude-2.1')->supportsImageInput())->toBeFalse();
    expect(makeAnthropic('claude-2.0')->supportsImageInput())->toBeFalse();
});

test('claude-instant-* does NOT support image input', function (): void {
    expect(makeAnthropic('claude-instant-1.2')->supportsImageInput())->toBeFalse();
});