<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\TokenEstimator;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

describe('TokenEstimator::estimateMessage', function (): void {

    it('returns at least 1 for empty content', function (): void {
        $estimator = new TokenEstimator();
        expect($estimator->estimateMessage(['role' => 'user', 'content' => '']))->toBeGreaterThanOrEqual(1);
    });

    it('estimates roughly 1 token per 4 characters', function (): void {
        $estimator = new TokenEstimator();
        $msg = ['role' => 'user', 'content' => str_repeat('a', 40)];
        // 40/4 = 10, plus 10 overhead = 20
        expect($estimator->estimateMessage($msg))->toBe(20);
    });

    it('adds overhead for tool_calls', function (): void {
        $estimator = new TokenEstimator();
        $msg = [
            'role' => 'assistant',
            'content' => 'ok',
            'tool_calls' => [
                ['function' => ['name' => 'foo', 'arguments' => '{"a":1}']],
            ],
        ];
        $withoutTool = $estimator->estimateMessage(['role' => 'assistant', 'content' => 'ok']);
        $withTool = $estimator->estimateMessage($msg);
        expect($withTool)->toBeGreaterThan($withoutTool);
    });

    it('handles tool_calls with missing arguments', function (): void {
        $estimator = new TokenEstimator();
        $msg = [
            'role' => 'assistant',
            'content' => 'ok',
            'tool_calls' => [
                ['function' => ['name' => 'foo']],
            ],
        ];
        expect($estimator->estimateMessage($msg))->toBeGreaterThan(0);
    });

    it('adds tool result overhead for role=tool', function (): void {
        $estimator = new TokenEstimator();
        $msg = ['role' => 'tool', 'content' => 'result'];
        $userMsg = ['role' => 'user', 'content' => 'result'];
        expect($estimator->estimateMessage($msg))->toBeGreaterThan($estimator->estimateMessage($userMsg));
    });

    it('counts non-string tool arguments (e.g. arrays) without adding tokens but still adds overhead', function (): void {
        $estimator = new TokenEstimator();
        $msg = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['function' => ['name' => 'foo', 'arguments' => ['nested' => 'value']]],
            ],
        ];
        expect($estimator->estimateMessage($msg))->toBeGreaterThan(0);
    });
});

describe('TokenEstimator::estimateSystemPrompt', function (): void {

    it('returns at least 1 for empty prompt', function (): void {
        $estimator = new TokenEstimator();
        expect($estimator->estimateSystemPrompt(''))->toBe(1);
    });

    it('estimates roughly 1 token per 4 characters', function (): void {
        $estimator = new TokenEstimator();
        // 100 chars / 4 = 25
        expect($estimator->estimateSystemPrompt(str_repeat('x', 100)))->toBe(25);
    });
});

describe('TokenEstimator::estimateTools', function (): void {

    it('returns 0 for empty tools array', function (): void {
        $estimator = new TokenEstimator();
        expect($estimator->estimateTools([]))->toBe(0);
    });

    it('estimates tokens for a tool with name, description, and parameters', function (): void {
        $estimator = new TokenEstimator();
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'foo',
                    'description' => 'bar',
                    'parameters' => ['type' => 'object'],
                ],
            ],
        ];
        // 3 chars/4 + 3 chars/4 + ~16 chars/4 + 20 overhead ≈ 0+0+4+20 = 24 (rounded)
        expect($estimator->estimateTools($tools))->toBeGreaterThan(20);
    });

    it('handles tools with only a name', function (): void {
        $estimator = new TokenEstimator();
        $tools = [['type' => 'function', 'function' => ['name' => 'a']]];
        expect($estimator->estimateTools($tools))->toBeGreaterThan(0);
    });

    it('handles tools with missing name', function (): void {
        $estimator = new TokenEstimator();
        $tools = [['type' => 'function', 'function' => []]];
        expect($estimator->estimateTools($tools))->toBeGreaterThan(0);
    });
});

describe('TokenEstimator::estimateRequest', function (): void {

    it('sums system prompt + tools + all message estimates', function (): void {
        $estimator = new TokenEstimator();
        $request = new LLMRequest(
            systemPrompt: 'You are helpful.',
            messages: [
                ['role' => 'user', 'content' => 'Hi'],
            ],
            tools: [
                ['type' => 'function', 'function' => ['name' => 'foo', 'description' => '', 'parameters' => []]],
            ],
        );

        $expected = $estimator->estimateSystemPrompt('You are helpful.')
            + $estimator->estimateTools($request->tools)
            + $estimator->estimateMessage(['role' => 'user', 'content' => 'Hi']);

        expect($estimator->estimateRequest($request))->toBe($expected);
    });

    it('returns the system-prompt estimate for a request with no messages or tools', function (): void {
        $estimator = new TokenEstimator();
        $request = new LLMRequest(systemPrompt: 'sys', messages: [], tools: []);
        $expected = $estimator->estimateSystemPrompt('sys');
        expect($estimator->estimateRequest($request))->toBe($expected);
    });
});

describe('TokenEstimator::estimateResponseTokens', function (): void {

    it('returns input + output tokens from the LLMResponse', function (): void {
        $estimator = new TokenEstimator();
        $response = new LLMResponse(content: '', toolCalls: [], inputTokens: 100, outputTokens: 50, completionId: 'test-id');
        expect($estimator->estimateResponseTokens($response))->toBe(150);
    });

    it('returns 0 for an empty response', function (): void {
        $estimator = new TokenEstimator();
        $response = new LLMResponse(content: null, toolCalls: [], inputTokens: 0, outputTokens: 0, completionId: 'test-id');
        expect($estimator->estimateResponseTokens($response))->toBe(0);
    });
});
