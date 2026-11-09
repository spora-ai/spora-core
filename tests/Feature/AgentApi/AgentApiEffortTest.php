<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Models\Task;
use Symfony\Component\HttpFoundation\Request;

/**
 * The agent API carries the reasoning effort of a message.
 *
 * The field means the same thing here as on the operator API: the effort of
 * the message being sent, and a level nobody knows is refused.
 */
it('forwards a named effort to the chat', function (): void {
    $seed = AgentApiTestSupport::seedAgent('effort');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
    );

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        effortAgentApiRequest($key, ['text' => 'hi', 'effort' => 'high']),
    );

    expect($response->getStatusCode())->toBe(202);

    $task = Task::query()->where('agent_id', $seed['agentId'])->orderByDesc('id')->first();
    expect($task?->effort)->toBe('high');
});

it('refuses an unknown effort with 422', function (): void {
    $seed = AgentApiTestSupport::seedAgent('effort-bad');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-bad');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
    );

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        effortAgentApiRequest($key, ['text' => 'hi', 'effort' => 'turbo']),
    );

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('INVALID_EFFORT');
});

it('refuses an effort the agent\'s driver does not offer with 422', function (): void {
    $seed = AgentApiTestSupport::seedAgent('effort-unsupported', AnthropicCompatibleDriver::class);
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-unsupported');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
    );

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        effortAgentApiRequest($key, ['text' => 'hi', 'effort' => 'none']),
    );

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED');
});

it('accepts an empty effort as the model default', function (): void {
    $seed = AgentApiTestSupport::seedAgent('effort-empty');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-empty');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
    );

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        effortAgentApiRequest($key, ['text' => 'hi', 'effort' => '']),
    );

    expect($response->getStatusCode())->toBe(202);

    $task = Task::query()->where('agent_id', $seed['agentId'])->orderByDesc('id')->first();
    expect($task?->effort)->toBeNull();
});

it('accepts a body without an effort at all', function (): void {
    $seed = AgentApiTestSupport::seedAgent('effort-absent');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-absent');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
    );

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        effortAgentApiRequest($key, ['text' => 'hi']),
    );

    expect($response->getStatusCode())->toBe(202);

    $task = Task::query()->where('agent_id', $seed['agentId'])->orderByDesc('id')->first();
    expect($task?->effort)->toBeNull();
});

/**
 * @param array<string, mixed> $body
 */
function effortAgentApiRequest(string $key, array $body): Request
{
    return Request::create(
        '/api/v1/agent-api/chats',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key],
        content: json_encode($body),
    );
}
