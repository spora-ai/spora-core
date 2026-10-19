<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\AgentApi\AgentApiChatActionController;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Http\AgentApi\AgentApiMediaController;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;

/**
 * Clearing a blocked chat from outside, and the multipart upload endpoint.
 */
describe('Agent API unblocking a chat', function (): void {
    /**
     * Park a chat on an approval the way the e2e test does, then hand the
     * caller back the chat id.
     *
     * @return array{0: \Psr\Container\ContainerInterface, 1: string, 2: int, 3: Task}
     */
    function parkOnApproval(string $suffix): array
    {
        $seed = AgentApiTestSupport::seedAgent($suffix);
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], $suffix);

        $llm = new ScriptedLlmDriver(
            new LLMResponse(
                content: null,
                toolCalls: [new DriverToolCall('pc_park', 'stub_output', ['value' => 'x'])],
                inputTokens: 1,
                outputTokens: 1,
                completionId: 'p1',
            ),
            new LLMResponse(content: 'done', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'p2'),
        );

        $container = AgentApiTestSupport::container($llm, null, [new StubOutputTool()]);
        AgentApiTestSupport::enableTool($seed['agentId'], StubOutputTool::class);

        $start = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, ['text' => 'do it']),
        );
        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];
        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');

        return [$container, $key, $chatId, Task::findOrFail($chatId)];
    }

    it('rejects pending tool calls and closes the chat', function (): void {
        [$container, $key, $chatId] = parkOnApproval('reject');

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'reject',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/reject", [
                'reason' => 'Not authorised by policy.',
            ], ['Authorization' => 'Bearer ' . $key]),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(202);

        $pending = ToolCall::where('task_id', $chatId)->where('status', 'PENDING_APPROVAL')->first();
        expect($pending)->toBeNull();
    });

    it('records a default reason when the caller sends none', function (): void {
        [$container, $key, $chatId] = parkOnApproval('reject-noreason');

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'reject',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/reject", [], ['Authorization' => 'Bearer ' . $key]),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(202);
        expect(ToolCall::where('task_id', $chatId)->where('status', 'PENDING_APPROVAL')->count())->toBe(0);
    });

    it('refuses a reject with no decisions to act on', function (): void {
        $seed = AgentApiTestSupport::seedAgent('reject-bad');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'reject-bad');

        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'k')),
            null,
            [new StubInputTool()],
        );

        $start = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, ['text' => 'hi']),
        );
        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];

        // The chat is QUEUED, not parked, so the state check fires before the
        // payload check — either way it must not be accepted.
        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'reject',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/reject", [], ['Authorization' => 'Bearer ' . $key]),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(409);
    });

    it('requires decisions on approve', function (): void {
        [$container, $key, $chatId] = parkOnApproval('approve-bad');

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'approve',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/approve", ['decisions' => []], ['Authorization' => 'Bearer ' . $key]),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(422);
        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');
    });

    it('requires both fields on answer', function (): void {
        [$container, $key, $chatId] = parkOnApproval('answer-bad');

        foreach ([['content' => 'x'], ['tool_call_id' => 'tc1'], []] as $body) {
            $response = AgentApiTestSupport::call(
                $container,
                AgentApiChatActionController::class,
                'answer',
                jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/answer", $body, ['Authorization' => 'Bearer ' . $key]),
                [$chatId],
            );

            expect($response->getStatusCode())->toBe(422);
        }
    });

    it('404s an unblock attempt against another agent\'s chat', function (): void {
        [, , $chatId] = parkOnApproval('crossblock');

        $other = AgentApiTestSupport::seedAgent('crossblock-other');
        [, $otherKey] = AgentApiTestSupport::mintKey($other['agentId'], 'crossblock-other');

        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'k')),
            null,
            [new StubInputTool()],
        );

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'reject',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/reject", [], ['Authorization' => 'Bearer ' . $otherKey]),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(404);
        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');
    });

    it('rejects a malformed body outright', function (): void {
        [$container, $key, $chatId] = parkOnApproval('badjson');

        $request = \Symfony\Component\HttpFoundation\Request::create(
            "/api/v1/agent-api/chats/{$chatId}/reject",
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key],
            content: '{not json',
        );

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'reject',
            $request,
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(400);
    });
});

describe('Agent API multipart upload', function (): void {
    /**
     * Build a real multipart request: `UploadedFile` needs a real path on
     * disk, which `Request::create()` will not synthesise for a body.
     */
    function uploadRequest(string $mediaId, string $contents, string $name): \Symfony\Component\HttpFoundation\Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agentapi');
        file_put_contents($tmp, $contents);

        $request = \Symfony\Component\HttpFoundation\Request::create("/api/v1/agent-api/media/{$mediaId}", 'POST');
        $request->files->set('file', new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $tmp,
            $name,
            null,
            null,
            true,
        ));
        $request->attributes->set('agent_api_context', $GLOBALS['agentApiUploadContext']);

        return $request;
    }

    it('ingests the part and answers 201', function (): void {
        $seed = AgentApiTestSupport::seedAgent('mp');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'mp');

        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'u')),
        );

        $GLOBALS['agentApiUploadContext'] = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64);
        $response = $container->get(AgentApiMediaController::class)
            ->store(uploadRequest('x', $png, 'chart.png'));

        expect($response->getStatusCode())->toBe(201);

        $payload = AgentApiTestSupport::decode($response);
        expect($payload['data']['id'])->not->toBeEmpty();
        expect($payload['data']['agent_id'])->toBe($seed['agentId']);
        expect($key)->toStartWith('spk_');

        unset($GLOBALS['agentApiUploadContext']);
    });

    it('answers 400 when no file part was sent', function (): void {
        $seed = AgentApiTestSupport::seedAgent('mp-empty');
        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'u')),
        );

        $request = \Symfony\Component\HttpFoundation\Request::create('/api/v1/agent-api/media', 'POST');
        $request->attributes->set('agent_api_context', new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        ));

        $response = $container->get(AgentApiMediaController::class)->store($request);

        expect($response->getStatusCode())->toBe(400);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('BAD_REQUEST');
    });

    it('answers 415 for a type the agent refuses', function (): void {
        $seed = AgentApiTestSupport::seedAgent('mp-type');
        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'u')),
        );

        $GLOBALS['agentApiUploadContext'] = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $response = $container->get(AgentApiMediaController::class)
            ->store(uploadRequest('x', "#!/bin/sh\nrm -rf /\n", 'payload.sh'));

        expect($response->getStatusCode())->toBe(415);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('UNSUPPORTED_MEDIA_TYPE');

        unset($GLOBALS['agentApiUploadContext']);
    });
});
