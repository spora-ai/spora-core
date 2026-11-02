<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Models\Task;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * The chat surface of the scoped API.
 *
 * A group key drives the chats of its group's agents; a chat outside the
 * group's reach is a 404, never a 403 — a caller must not be able to probe
 * which chat ids exist.
 */
describe('chats on the group surface', function (): void {
    it('starts a chat with one of the group\'s agents', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-start');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $group['agentIds'][0], 'text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(202);

        $chat = ScopedApiTestSupport::data($response)['chat'];
        expect($chat['agent_id'])->toBe($group['agentIds'][0]);
        expect(Task::find($chat['id']))->not->toBeNull();
    });

    it('lists the chats of the group\'s agents', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-list');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        Task::create([
            'agent_id' => $group['agentIds'][0],
            'principal_id' => $group['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'index',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/chats', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(count(ScopedApiTestSupport::data($response)['chats']))->toBe(1);
    });

    it('404s a chat of another group rather than 403', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-other');
        $other = AgentApiTestSupport::seedGroup('chat-other-2');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        $theirChat = Task::create([
            'agent_id' => $other['agentIds'][0],
            'principal_id' => $other['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'status',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/chats/1/status', [], 'GET'),
            [(int) $theirChat->id],
        );

        expect($response->getStatusCode())->toBe(404);
        expect(ScopedApiTestSupport::errorCode($response))->toBe('NOT_FOUND');
    });

    it('404s an agent outside the group\'s reach when starting a chat', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-agent-outside');
        $other = AgentApiTestSupport::seedGroup('chat-agent-outside-2');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $other['agentIds'][0], 'text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(404);
    });

    it('refuses a chat without an agent', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-no-agent');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('does not let a group key drive a personal agent', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('chat-personal');
        $personal = AgentApiTestSupport::seedAgent('chat-personal-agent');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'chat');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $personal['agentId'], 'text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(404);
    });
});

describe('chats on the global surface', function (): void {
    it('reaches the chats of every group-owned agent', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $first = AgentApiTestSupport::seedGroup('global-chat-a');
        $second = AgentApiTestSupport::seedGroup('global-chat-b');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-chat');

        Task::create([
            'agent_id' => $first['agentIds'][0],
            'principal_id' => $first['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        Task::create([
            'agent_id' => $second['agentIds'][0],
            'principal_id' => $second['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiChatController::class,
            'index',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/chats', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(count(ScopedApiTestSupport::data($response)['chats']))->toBe(2);
    });

    it('does not reach the chats of a personal agent', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $personal = AgentApiTestSupport::seedAgent('global-chat-personal');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-chat');

        Task::create([
            'agent_id' => $personal['agentId'],
            'principal_id' => $personal['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiChatController::class,
            'index',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/chats', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ScopedApiTestSupport::data($response)['chats'])->toBe([]);
    });
});
