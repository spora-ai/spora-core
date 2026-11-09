<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Models\ApiCallback;
use Spora\Models\Task;
use Spora\Services\ApiKeys\ApiKeyCallbackDispatcher;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * The callback outbox on the scoped surfaces.
 *
 * The signature recipe does not care which scope a key has: a receiver
 * written against the per-agent API verifies a group-scoped callback the
 * same way, because the signature is keyed on the digest the key row
 * stores.
 */
describe('callbacks on the scoped surfaces', function (): void {
    it('records a callback against the group key that started the chat', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('callback-group');
        [$keyRow, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'callback');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                [
                    'agent_id' => $group['agentIds'][0],
                    'text' => 'hi',
                    'callback_url' => 'https://93.184.216.34/spora',
                ],
            ),
        );

        expect($response->getStatusCode())->toBe(202);

        $chatId = (int) ScopedApiTestSupport::data($response)['chat']['id'];
        $callback = ApiCallback::where('chat_id', $chatId)->first();

        expect($callback)->not->toBeNull();
        expect($callback->key_id)->toBe($keyRow->id);
        expect((int) $callback->agent_id)->toBe($group['agentIds'][0]);
        // Armed at creation: a row the sweep cannot see is a callback that
        // never fires.
        expect($callback->next_attempt_at)->not->toBeNull();
    });

    it('signs a group-scoped callback with the group key\'s digest', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('callback-signature');
        [$keyRow, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'callback');

        $http = new \Tests\Feature\AgentApi\RecordingHttpClient();
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm(), $http);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                [
                    'agent_id' => $group['agentIds'][0],
                    'text' => 'hi',
                    'callback_url' => 'https://93.184.216.34/spora',
                ],
            ),
        );

        $chatId = (int) ScopedApiTestSupport::data($response)['chat']['id'];

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect($container->get(ApiKeyCallbackDispatcher::class)->dispatchDue())->toBe(1);
        expect($http->requests)->toHaveCount(1);

        // The receiver holds the plaintext secret and reproduces the
        // signature from the digest the row stores — which is what makes a
        // group-scoped callback verifiable by the same code as a per-agent
        // one.
        $signature = $http->header(0, 'X-Spora-Signature');
        $timestamp = (string) $http->header(0, 'X-Spora-Timestamp');

        expect($signature)->not->toBeNull();
        expect($timestamp)->not->toBeNull();
        expect(hash_hmac('sha256', $timestamp . '.' . $http->body(0), $keyRow->secret_hash))
            ->toBe($signature);
    });

    it('does not record a callback when the caller named no url', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('callback-none');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'callback');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $group['agentIds'][0], 'text' => 'hi'],
            ),
        );

        $chatId = (int) ScopedApiTestSupport::data($response)['chat']['id'];

        expect(ApiCallback::where('chat_id', $chatId)->count())->toBe(0);
        expect(Task::findOrFail($chatId))->not->toBeNull();
    });
});
