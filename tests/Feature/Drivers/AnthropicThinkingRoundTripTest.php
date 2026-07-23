<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\ContentBlock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Asserts an Anthropic response with thinking and redacted_thinking
 * blocks round-trips through the driver into structured ContentBlocks
 * with the signature byte-identical.
 */
test('Anthropic thinking and redacted_thinking blocks round-trip with signatures', function (): void {
    $payload = json_encode([
        'id' => 'msg_thinking_round_trip',
        'stop_reason' => 'end_turn',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'Plan: search then summarize', 'signature' => 'sig-abc-123'],
            ['type' => 'redacted_thinking', 'data' => 'encrypted-payload-xyz'],
            ['type' => 'text', 'text' => 'Summary: the answer is 42.'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    );

    $response = $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->content)->toBe('Summary: the answer is 42.')
        ->and($response->contentBlocks)->toHaveCount(3);

    $thinking = $response->contentBlocks[0];
    expect($thinking)->toBeInstanceOf(ContentBlock::class);
    expect($thinking->type)->toBe(ContentBlock::TYPE_THINKING);
    expect($thinking->text)->toBe('Plan: search then summarize');
    expect($thinking->signature)->toBe('sig-abc-123');

    $redacted = $response->contentBlocks[1];
    expect($redacted->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING);
    expect($redacted->data)->toBe('encrypted-payload-xyz');

    $text = $response->contentBlocks[2];
    expect($text->type)->toBe(ContentBlock::TYPE_TEXT);
    expect($text->text)->toBe('Summary: the answer is 42.');
});
