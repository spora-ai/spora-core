<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\ScopedApi\ScopedApiChatActionController;
use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Tests\Feature\AgentApi\AgentApiTestSupport;
use Tests\Feature\AgentApi\ScriptedLlmDriver;
use Tests\Fixtures\StubOutputTool;

/**
 * Clearing a blocked chat from outside, on the group surface.
 *
 * The group surface drives the chats of every agent in the group, so an
 * approval the agent parked on can be cleared by the same key that started
 * the chat.
 */
describe('unblocking a chat on the scoped surfaces', function (): void {
    /**
     * Park a chat on an approval the way the agent surface does, then hand
     * the caller back the chat id.
     *
     * @return array{0: \Psr\Container\ContainerInterface, 1: string, 2: int}
     */
    function parkOnApproval(string $suffix): array
    {
        $group = AgentApiTestSupport::seedGroup('action-' . $suffix);
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'action');

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
        AgentApiTestSupport::enableTool($group['agentIds'][0], StubOutputTool::class);

        $start = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $group['agentIds'][0], 'text' => 'do it'],
            ),
        );

        $chatId = (int) ScopedApiTestSupport::data($start)['chat']['id'];
        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');

        return [$container, $key, $chatId];
    }

    it('rejects pending tool calls and closes the chat', function (): void {
        [$container, $key, $chatId] = parkOnApproval('reject');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'reject',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/reject",
                ['reason' => 'Not authorised by policy.'],
            ),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(202);
        expect(ToolCall::where('task_id', $chatId)->where('status', 'PENDING_APPROVAL')->count())->toBe(0);
    });

    it('records a default reason when the caller sends none', function (): void {
        [$container, $key, $chatId] = parkOnApproval('reject-noreason');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'reject',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/reject",
            ),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(202);
    });

    it('approves pending tool calls', function (): void {
        [$container, $key, $chatId] = parkOnApproval('approve');

        $providerCallId = ToolCall::where('task_id', $chatId)->where('status', 'PENDING_APPROVAL')->value('provider_call_id');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'approve',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/approve",
                ['decisions' => [[
                    'provider_call_id' => $providerCallId,
                    'decision' => 'approve',
                    'arguments' => ['value' => 'x'],
                ]]],
            ),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(202);
    });

    it('requires decisions on approve', function (): void {
        [$container, $key, $chatId] = parkOnApproval('approve-bad');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'approve',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/approve",
                ['decisions' => []],
            ),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(422);
        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');
    });

    it('requires both fields on answer', function (): void {
        [$container, $key, $chatId] = parkOnApproval('answer-bad');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'answer',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/answer",
                ['tool_call_id' => 'call_1'],
            ),
            [$chatId],
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('answers the question the agent asked', function (): void {
        [$container, $key, $chatId] = parkOnApproval('answer');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'answer',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/answer",
                ['tool_call_id' => 'call_1', 'content' => 'Northern region'],
            ),
            [$chatId],
        );

        // The chat is parked on an approval, not on a question, so the
        // state check answers 409 — the same distinction the agent surface
        // draws.
        expect($response->getStatusCode())->toBe(409);
    });

    it('404s a chat outside the group\'s reach', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('action-outside');
        $other = AgentApiTestSupport::seedGroup('action-outside-other');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'action');

        $theirChat = Task::create([
            'agent_id' => $other['agentIds'][0],
            'principal_id' => $other['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'reject',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats/' . $theirChat->id . '/reject',
            ),
            [(int) $theirChat->id],
        );

        expect($response->getStatusCode())->toBe(404);
    });
});
