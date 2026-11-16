<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Core\Config;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Models\Task;

/**
 * The agent API's step bounds follow the instance's ceiling.
 *
 * The ceiling is the largest cap an agent may carry, so the surfaces that
 * take one take the ceiling's — a hard-coded 100 would refuse a cap the
 * instance allows, and an operator who raises the ceiling for long-running
 * workflows would find the agent API never hands it out.
 *
 * The additional steps a *continue* may add are not the agent's cap. They
 * carry their own policy bound, and the service refuses anything above it
 * — so a controller that clamped to the ceiling would pass a request the
 * service then rejects, turning a request that used to succeed into one
 * that fails downstream.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

/**
 * The agent API's step bounds.
 */
it('hands out a cap the ceiling allows, not a hard-coded one', function (): void {
    $seed = AgentApiTestSupport::seedAgent('cap');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'cap');

    $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'c1'));
    $container = AgentApiTestSupport::container($llm);
    $container->set(Config::class, new Config([
        'SPORA_AGENT_API'          => '1',
        'SPORA_APP_ENV'            => 'testing',
        'SPORA_LOG_LEVEL'          => 'error',
        'SPORA_API_RATE_LIMIT_MAX' => '1000',
        'SPORA_MAX_STEPS'          => '500',
    ]));

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        jsonRequest('POST', '/api/v1/agent-api/chats', [
            'text'      => 'hello',
            'max_steps' => 400,
        ], ['Authorization' => 'Bearer ' . $key]),
    );

    expect($response->getStatusCode())->toBe(202);

    // The cap the caller named is the cap the task carries: the ceiling
    // allowed it, so nothing lowered it.
    $task = Task::where('agent_id', $seed['agentId'])->latest('id')->first();
    expect((int) $task->max_steps)->toBe(400);
});

it('lowers a cap above the ceiling to it', function (): void {
    $seed = AgentApiTestSupport::seedAgent('over');
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'over');

    $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'c2'));
    $container = AgentApiTestSupport::container($llm);
    $container->set(Config::class, new Config([
        'SPORA_AGENT_API'          => '1',
        'SPORA_APP_ENV'            => 'testing',
        'SPORA_LOG_LEVEL'          => 'error',
        'SPORA_API_RATE_LIMIT_MAX' => '1000',
        'SPORA_MAX_STEPS'          => '50',
    ]));

    $response = AgentApiTestSupport::call(
        $container,
        AgentApiChatController::class,
        'store',
        jsonRequest('POST', '/api/v1/agent-api/chats', [
            'text'      => 'hello',
            'max_steps' => 400,
        ], ['Authorization' => 'Bearer ' . $key]),
    );

    expect($response->getStatusCode())->toBe(202);

    $task = Task::where('agent_id', $seed['agentId'])->latest('id')->first();
    expect((int) $task->max_steps)->toBe(50);
});
