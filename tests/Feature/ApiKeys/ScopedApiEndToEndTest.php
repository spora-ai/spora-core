<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\ScopedApi\ScopedApiChatActionController;
use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Models\Task;
use Tests\Feature\AgentApi\AgentApiTestSupport;
use Tests\Feature\AgentApi\RecordingHttpClient;
use Tests\Feature\AgentApi\ScriptedLlmDriver;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;

/**
 * End-to-end for the scoped API: mint a key, start a chat through the real
 * HTTP surface, let the real orchestrator run it, then read the answer back.
 *
 * The scoped counterpart of `AgentApiEndToEndTest`. That file pins the agent
 * surface; this one pins the group and global surfaces, which drive the same
 * machinery through a different reach rule — a chat of another group is a
 * `404` there, and the callback is signed with a different key's digest.
 *
 * Two scenarios, because they are the two shapes an integrator hits. A: the
 * chat runs to completion and the callback fires once. B: the chat parks on an
 * approval the caller has to give, which means it settles *twice* — and a
 * callback must therefore be delivered twice. A dispatcher that fired only on
 * the terminal transition would pass A and fail B.
 *
 * The container is the real `ContainerDefinitions::all()` graph, so the DI
 * wiring is under test too; only the LLM driver and the HTTP client are
 * substituted.
 */
describe('scoped API end-to-end', function (): void {
    it('runs a chat to completion and delivers one signed callback', function (): void {
        $group = AgentApiTestSupport::seedGroup('e2e');
        [$keyRow, $plaintext] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'e2e');

        $llm = new ScriptedLlmDriver(
            new LLMResponse(
                content: null,
                toolCalls: [new DriverToolCall('pc_1', 'stub_input', [])],
                inputTokens: 10,
                outputTokens: 5,
                completionId: 'c1',
            ),
            new LLMResponse(content: 'All done.', toolCalls: [], inputTokens: 5, outputTokens: 3, completionId: 'c2'),
        );

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubInputTool()]);
        AgentApiTestSupport::enableTool($group['agentIds'][0], StubInputTool::class);

        $start = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $plaintext,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                [
                    'agent_id' => $group['agentIds'][0],
                    'text' => 'Summarise Q3',
                    'callback_url' => 'https://hooks.example.test/spora',
                ],
            ),
        );

        expect($start->getStatusCode())->toBe(202);

        $chatId = (int) ScopedApiTestSupport::data($start)['chat']['id'];

        // The chat is attributed to the group's agent and to the group's
        // principal, and the caller cannot redirect it by naming another
        // agent — the reach rule is the group's, not the body's.
        $task = Task::findOrFail($chatId);
        expect((int) $task->agent_id)->toBe($group['agentIds'][0]);
        expect((int) $task->principal_id)->toBe($group['principalId']);

        $callback = AgentApiTestSupport::callbackFor($chatId);
        expect($callback->pending_status)->toBeNull();
        expect($callback->url)->toBe('https://hooks.example.test/spora');
        expect($callback->next_attempt_at)->not->toBeNull();

        // A sweep while the chat is still QUEUED must post nothing.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);
        expect($http->requests)->toBe([]);

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('COMPLETED');

        // The answer comes back over the group's own surface.
        $status = ScopedApiTestSupport::data(ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'status',
            ScopedApiTestSupport::bearer($plaintext, ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/status", [], 'GET'),
            [$chatId],
        ))['chat'];

        expect($status['status'])->toBe('COMPLETED');
        expect($status['answer'])->toBe('All done.');
        expect($status['blocked'])->toBeNull();

        // Now the sweep has something to deliver, and delivers it once.
        AgentApiTestSupport::skipBackoff((int) $callback->id);

        expect(AgentApiTestSupport::dispatchDue($container))->toBe(1);
        expect($http->requests)->toHaveCount(1);
        expect($http->requests[0]['method'])->toBe('POST');
        expect($http->requests[0]['url'])->toBe('https://hooks.example.test/spora');

        $payload = json_decode($http->body(0), true);
        expect($payload['status'])->toBe('COMPLETED');
        expect($payload['answer'])->toBe('All done.');

        // The signature is keyed on the digest the row already stores, which
        // is what lets a receiver verify without Spora ever holding the
        // secret again — and it is the *group* key's digest, because the
        // group key is the one that started the chat.
        expect($http->header(0, 'X-Spora-Key-Id'))->toBe($keyRow->id);
        expect($http->header(0, 'X-Spora-Signature'))->toBe(hash_hmac(
            'sha256',
            $http->header(0, 'X-Spora-Timestamp') . '.' . $http->body(0),
            $keyRow->secret_hash,
        ));

        // A second sweep has nothing left to say.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);
        expect($http->requests)->toHaveCount(1);
    });

    it('delivers a second callback when an approved chat settles again', function (): void {
        $group = AgentApiTestSupport::seedGroup('blocked');
        [, $plaintext] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'blocked');

        $llm = new ScriptedLlmDriver(
            // `stub_output` requires approval by default, so the chat parks.
            new LLMResponse(
                content: null,
                toolCalls: [new DriverToolCall('pc_block', 'stub_output', ['value' => 'x'])],
                inputTokens: 10,
                outputTokens: 5,
                completionId: 'b1',
            ),
            new LLMResponse(content: 'Approved and done.', toolCalls: [], inputTokens: 5, outputTokens: 3, completionId: 'b2'),
        );

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubOutputTool()]);
        AgentApiTestSupport::enableTool($group['agentIds'][0], StubOutputTool::class);

        $start = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $plaintext,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                [
                    'agent_id' => $group['agentIds'][0],
                    'text' => 'Send it',
                    'callback_url' => 'https://hooks.example.test/spora',
                ],
            ),
        );

        $chatId = (int) ScopedApiTestSupport::data($start)['chat']['id'];
        $callback = AgentApiTestSupport::callbackFor($chatId);

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');

        // The caller can see what it has to decide, without the transcript.
        $status = ScopedApiTestSupport::data(ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'status',
            ScopedApiTestSupport::bearer($plaintext, ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/status", [], 'GET'),
            [$chatId],
        ))['chat'];

        expect($status['blocked']['reason'])->toBe('PENDING_APPROVAL');
        expect($status['blocked']['tool_calls'][0]['name'])->toBe('stub_output');

        // Parking is a settle, so the receiver is told about it.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(1);
        expect(json_decode($http->body(0), true)['status'])->toBe('PENDING_APPROVAL');
        expect(json_decode($http->body(0), true)['blocked']['reason'])->toBe('PENDING_APPROVAL');

        // The same group key that started the chat clears it — the group
        // surface drives the chats of its own agents.
        $approve = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatActionController::class,
            'approve',
            ScopedApiTestSupport::bearer(
                $plaintext,
                ScopedApiTestSupport::GROUP_PREFIX . "/chats/{$chatId}/approve",
                [
                    'decisions' => [[
                        'provider_call_id' => 'pc_block',
                        'decision' => 'approve',
                        'arguments' => ['value' => 'x'],
                    ]],
                ],
            ),
            [$chatId],
        );

        expect($approve->getStatusCode())->toBe(202);
        expect(Task::findOrFail($chatId)->status)->toBe('QUEUED');

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('COMPLETED');

        // The second settle is a different status, so it is a new delivery.
        // The row stayed armed through the blocked phase rather than being
        // disarmed after the first delivery — that is what makes a second
        // settlement reachable at all.
        AgentApiTestSupport::skipBackoff((int) $callback->id);

        expect(AgentApiTestSupport::dispatchDue($container))->toBe(1);
        expect($http->requests)->toHaveCount(2);
        expect(json_decode($http->body(1), true)['status'])->toBe('COMPLETED');
        expect(json_decode($http->body(1), true)['answer'])->toBe('Approved and done.');
    });

    it('runs a chat on the global surface and signs the callback with the global key', function (): void {
        $group = AgentApiTestSupport::seedGroup('global-e2e');
        [, $plaintext] = AgentApiTestSupport::mintGlobalKey('global-e2e');

        $llm = new ScriptedLlmDriver(
            new LLMResponse(content: 'Install-wide answer.', toolCalls: [], inputTokens: 5, outputTokens: 3, completionId: 'g1'),
        );

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubInputTool()]);

        $start = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $plaintext,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/chats',
                [
                    'agent_id' => $group['agentIds'][0],
                    'text' => 'Say hello',
                    'callback_url' => 'https://hooks.example.test/spora',
                ],
            ),
        );

        expect($start->getStatusCode())->toBe(202);

        $chatId = (int) ScopedApiTestSupport::data($start)['chat']['id'];

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('COMPLETED');

        $status = ScopedApiTestSupport::data(ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiChatController::class,
            'status',
            ScopedApiTestSupport::bearer($plaintext, ScopedApiTestSupport::GLOBAL_PREFIX . "/chats/{$chatId}/status", [], 'GET'),
            [$chatId],
        ))['chat'];

        expect($status['answer'])->toBe('Install-wide answer.');

        $callback = AgentApiTestSupport::callbackFor($chatId);
        AgentApiTestSupport::skipBackoff((int) $callback->id);

        expect(AgentApiTestSupport::dispatchDue($container))->toBe(1);
        expect($http->requests)->toHaveCount(1);
        expect($http->header(0, 'X-Spora-Key-Id'))->toBe($callback->key_id);
    });

    it('refuses a chat of another group, and does not deliver its callback', function (): void {
        $group = AgentApiTestSupport::seedGroup('reach-e2e');
        $other = AgentApiTestSupport::seedGroup('reach-e2e-other');
        [, $plaintext] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'reach-e2e');

        $llm = new ScriptedLlmDriver(
            new LLMResponse(content: 'ok', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'r1'),
        );

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubInputTool()]);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiChatController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $plaintext,
                ScopedApiTestSupport::GROUP_PREFIX . '/chats',
                ['agent_id' => $other['agentIds'][0], 'text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(404);
        // The agent is reported as absent rather than as out of reach: a
        // caller must not learn that the agent exists in another group.
        expect(ScopedApiTestSupport::errorCode($response))->toBe('AGENT_NOT_FOUND');

        // Nothing was queued, so nothing can be delivered.
        expect($http->requests)->toBe([]);
    });
});
