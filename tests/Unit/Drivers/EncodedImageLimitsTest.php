<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\EncodedImageLimits;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The per-image ceiling each provider puts on the **encoded** image.
 *
 * The numbers are the providers' own: the Claude API takes 10 MB of
 * base64 per image, Bedrock only 5 MB, OpenAI 20 MB. What matters here
 * is that each driver states the ceiling of the endpoint it actually
 * talks to — the same image is acceptable to one and a 400 at the other.
 */
function anthropicDriverForBaseUrl(string $baseUrl): AnthropicCompatibleDriver
{
    return new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-sonnet-5-5',
        baseUrl: $baseUrl,
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
        timeout: 60,
    );
}

it('states the Claude API ceiling for the Anthropic driver', function (): void {
    expect(anthropicDriverForBaseUrl('https://api.anthropic.com')->maxEncodedImageBytes())
        ->toBe(EncodedImageLimits::CLAUDE_API_MAX_ENCODED_BYTES);
});

it('states the Bedrock ceiling when the Anthropic driver talks to Bedrock', function (): void {
    // Bedrock's Anthropic-native route is the one the driver already
    // speaks, and it halves the ceiling the Claude API allows.
    expect(anthropicDriverForBaseUrl('https://bedrock-runtime.us-east-1.amazonaws.com/anthropic')->maxEncodedImageBytes())
        ->toBe(EncodedImageLimits::BEDROCK_MAX_ENCODED_BYTES);

    expect(anthropicDriverForBaseUrl('https://bedrock-mantle.us-east-1.api.aws/anthropic')->maxEncodedImageBytes())
        ->toBe(EncodedImageLimits::BEDROCK_MAX_ENCODED_BYTES);
});

it('states the OpenAI ceiling for both OpenAI drivers', function (): void {
    $chat = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
        timeout: 60,
    );
    $responses = new OpenAIResponsesDriver(
        apiKey: 'test',
        model: 'gpt-6-astra',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
        timeout: 60,
    );

    expect($chat->maxEncodedImageBytes())->toBe(EncodedImageLimits::OPENAI_MAX_ENCODED_BYTES);
    expect($responses->maxEncodedImageBytes())->toBe(EncodedImageLimits::OPENAI_MAX_ENCODED_BYTES);
});

it('assumes the strictest known ceiling for a driver that states none', function (): void {
    $driver = new class implements LLMDriverInterface {
        public function complete(LLMRequest $request): LLMResponse
        {
            throw new RuntimeException('This driver is never asked to complete.');
        }

        public function getProviderName(): string
        {
            return 'test_driver';
        }

        public function getModelName(): string
        {
            return 'test-model';
        }

        public function supportsImageInput(): bool
        {
            return true;
        }
    };

    expect(EncodedImageLimits::maxEncodedBytesFor($driver))->toBe(EncodedImageLimits::SAFE_MAX_ENCODED_BYTES);
    expect(EncodedImageLimits::maxEncodedBytesFor(null))->toBe(EncodedImageLimits::SAFE_MAX_ENCODED_BYTES);
});

it('turns an encoded ceiling into the file that fits', function (): void {
    // Base64 spends four bytes on every three, so the file that fits is
    // three quarters of the ceiling — and one byte more does not.
    $raw = EncodedImageLimits::maxRawBytesFor(EncodedImageLimits::BEDROCK_MAX_ENCODED_BYTES);

    expect($raw)->toBe(3_750_000);
    expect(strlen(base64_encode(str_repeat('x', $raw))))->toBe(EncodedImageLimits::BEDROCK_MAX_ENCODED_BYTES);
    expect(strlen(base64_encode(str_repeat('x', $raw + 1))))->toBeGreaterThan(EncodedImageLimits::BEDROCK_MAX_ENCODED_BYTES);
});
