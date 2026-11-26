<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\RequestLimits;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The ceilings each provider puts on an image and on a request.
 *
 * The numbers are the providers' own: the Claude API takes 10 MB of
 * base64 per image and a 128 MB request, Bedrock only 5 MB per image and
 * a 20 MB request, OpenAI 20 MB per image and a 512 MB request. What
 * matters here is that each driver states the ceiling of the endpoint it
 * actually talks to — the same image is acceptable to one and a 400 at
 * the other.
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

it('states the Claude API ceilings for the Anthropic driver', function (): void {
    $driver = anthropicDriverForBaseUrl('https://api.anthropic.com');

    expect($driver->maxEncodedImageBytes())->toBe(RequestLimits::CLAUDE_API_MAX_IMAGE_BYTES);
    expect($driver->maxRequestBytes())->toBe(RequestLimits::CLAUDE_API_MAX_REQUEST_BYTES);
    expect($driver->maxImagesPerRequest())->toBe(RequestLimits::CLAUDE_API_MAX_IMAGES);
});

it('states the Bedrock ceilings when the Anthropic driver talks to Bedrock', function (): void {
    // Bedrock's Anthropic-native route is the one the driver already
    // speaks, and it halves the image ceiling the Claude API allows.
    $runtime = anthropicDriverForBaseUrl('https://bedrock-runtime.us-east-1.amazonaws.com/anthropic');
    $mantle  = anthropicDriverForBaseUrl('https://bedrock-mantle.us-east-1.api.aws/anthropic');

    expect($runtime->maxEncodedImageBytes())->toBe(RequestLimits::BEDROCK_MAX_IMAGE_BYTES);
    expect($runtime->maxRequestBytes())->toBe(RequestLimits::BEDROCK_MAX_REQUEST_BYTES);
    expect($mantle->maxEncodedImageBytes())->toBe(RequestLimits::BEDROCK_MAX_IMAGE_BYTES);
    expect($mantle->maxRequestBytes())->toBe(RequestLimits::BEDROCK_MAX_REQUEST_BYTES);
});

it('states the OpenAI ceilings for the Chat Completions driver', function (): void {
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
        timeout: 60,
    );

    expect($driver->maxEncodedImageBytes())->toBe(RequestLimits::OPENAI_MAX_IMAGE_BYTES);
    expect($driver->maxRequestBytes())->toBe(RequestLimits::OPENAI_MAX_REQUEST_BYTES);
    expect($driver->maxImagesPerRequest())->toBe(RequestLimits::OPENAI_MAX_IMAGES);
});

it('states the Responses image ceiling with the data-URL prefix counted against it', function (): void {
    // The Responses route bounds the whole `image_url` string, and the
    // image travels as such a string — so the prefix is part of the
    // ceiling, not something that sits beside it.
    $driver = new OpenAIResponsesDriver(
        apiKey: 'test',
        model: 'gpt-6-astra',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
        timeout: 60,
    );

    expect($driver->maxEncodedImageBytes())
        ->toBe(RequestLimits::OPENAI_RESPONSES_MAX_IMAGE_BYTES - RequestLimits::DATA_URL_PREFIX_BYTES);
    expect($driver->maxRequestBytes())->toBe(RequestLimits::OPENAI_MAX_REQUEST_BYTES);
});

it('assumes the strictest known ceilings for a driver that states none', function (): void {
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

    expect(RequestLimits::maxEncodedImageBytesFor($driver))->toBe(RequestLimits::SAFE_MAX_IMAGE_BYTES);
    expect(RequestLimits::maxEncodedImageBytesFor(null))->toBe(RequestLimits::SAFE_MAX_IMAGE_BYTES);
    expect(RequestLimits::maxRequestBytesFor($driver))->toBe(RequestLimits::SAFE_MAX_REQUEST_BYTES);
    expect(RequestLimits::maxRequestBytesFor(null))->toBe(RequestLimits::SAFE_MAX_REQUEST_BYTES);
    expect(RequestLimits::maxImagesFor($driver))->toBe(RequestLimits::SAFE_MAX_IMAGES);
    expect(RequestLimits::maxImagesFor(null))->toBe(RequestLimits::SAFE_MAX_IMAGES);
});

it('turns an encoded ceiling into the file that fits', function (): void {
    // Base64 spends four bytes on every three, so the file that fits is
    // three quarters of the ceiling — and one byte more does not.
    $raw = RequestLimits::maxRawBytesFor(RequestLimits::BEDROCK_MAX_IMAGE_BYTES);

    expect($raw)->toBe(3_750_000);
    expect(strlen(base64_encode(str_repeat('x', $raw))))->toBe(RequestLimits::BEDROCK_MAX_IMAGE_BYTES);
    expect(strlen(base64_encode(str_repeat('x', $raw + 1))))->toBeGreaterThan(RequestLimits::BEDROCK_MAX_IMAGE_BYTES);
});

it('leaves a quarter of the request to the text', function (): void {
    // The request carries the system prompt, the tools and the
    // conversation text as well as the images, and only the images are
    // sized here.
    expect(RequestLimits::maxImageBytesForRequest(RequestLimits::BEDROCK_MAX_REQUEST_BYTES))
        ->toBe(15_000_000);
    expect(RequestLimits::maxImageBytesForRequest(RequestLimits::CLAUDE_API_MAX_REQUEST_BYTES))
        ->toBe(96_000_000);
    expect(RequestLimits::maxImageBytesForRequest(RequestLimits::OPENAI_MAX_REQUEST_BYTES))
        ->toBe(384_000_000);
});
