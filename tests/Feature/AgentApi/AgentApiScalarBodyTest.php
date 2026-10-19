<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentApi\AgentApiChatController;

/**
 * A body that is valid JSON but not an object used to be a 500.
 *
 * `null` is the shape a client sends for "no body" far more often than
 * anyone would guess, and every one of them got an unhandled TypeError.
 */
it('accepts a JSON scalar body without dying', function (string $body): void {
    $seed = AgentApiTestSupport::seedAgent('scalar' . crc32($body));
    [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'scalar');

    $container = AgentApiTestSupport::container(
        new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's')),
        null,
        [new \Tests\Fixtures\StubInputTool()],
    );

    $request = \Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/agent-api/chats',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key],
        content: $body,
    );

    $response = AgentApiTestSupport::call($container, AgentApiChatController::class, 'store', $request);

    // 422, not 500: the body is understood and carries no `text`.
    expect($response->getStatusCode())->toBe(422);
})->with([
    'null' => ['null'],
    'a bare string' => ['"just a string"'],
    'a number' => ['42'],
    'a boolean' => ['true'],
]);
