<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Illuminate\Support\Carbon;
use Spora\Core\Config;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\AgentApi\AgentApiChatActionController;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Models\RatelimitHit;
use Spora\Models\Task;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;

/**
 * End-to-end for the Agent API: mint a key, start a chat through the real
 * HTTP surface, let the real orchestrator run it, then read the answer back.
 *
 * Two scenarios, because they are the two shapes an integrator hits. A: the
 * chat runs to completion and the callback fires once. B: the chat parks on
 * an approval the caller has to give, which means it settles *twice* — and
 * that a callback must therefore be delivered twice. A dispatcher that
 * fired only on the terminal transition would pass A and fail B.
 *
 * The container is the real `ContainerDefinitions::all()` graph, so the DI
 * wiring is under test too; only the LLM driver and the HTTP client are
 * substituted. See `AgentApiTestSupport` for why the scaffolding is a class.
 */
describe('Agent API end-to-end', function (): void {
    it('runs a chat to completion and delivers one signed callback', function (): void {
        $seed = AgentApiTestSupport::seedAgent('e2e');
        [$key, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'e2e');

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
        AgentApiTestSupport::enableTool($seed['agentId'], StubInputTool::class);

        $startResponse = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($plaintext, [
                'text' => 'Summarise Q3',
                'callback_url' => 'https://hooks.example.test/spora',
            ]),
        );

        expect($startResponse->getStatusCode())->toBe(202);

        $chatId = (int) AgentApiTestSupport::decode($startResponse)['data']['chat']['id'];

        // The chat is attributed to the key's agent and the principal owner,
        // and the caller cannot redirect it by naming another agent.
        $task = Task::findOrFail($chatId);
        expect((int) $task->agent_id)->toBe($seed['agentId']);
        expect((int) $task->trigger_user_id)->toBe($seed['userId']);
        expect($task->status)->toBe('QUEUED');

        // The callback row exists before the chat has settled — this is what
        // lets delivery work without a task-lifecycle hook — and it is
        // armed at creation. A row left unarmed is invisible to the sweep,
        // which filters on `next_attempt_at IS NOT NULL`, and no callback
        // would ever be delivered.
        $callback = AgentApiTestSupport::callbackFor($chatId);
        expect($callback->pending_status)->toBeNull();
        expect($callback->url)->toBe('https://hooks.example.test/spora');
        expect($callback->next_attempt_at)->not->toBeNull();

        // A sweep while the chat is still QUEUED must post nothing.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);
        expect($http->requests)->toBe([]);

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('COMPLETED');

        // `/status` is the default projection: the answer, and no tool calls.
        $statusResponse = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'status',
            jsonRequest('GET', "/api/v1/agent-api/chats/{$chatId}/status", [], ['Authorization' => 'Bearer ' . $plaintext]),
            [$chatId],
        );
        $status = AgentApiTestSupport::decode($statusResponse)['data']['chat'];

        expect($statusResponse->getStatusCode())->toBe(200);
        expect($status['status'])->toBe('COMPLETED');
        expect($status['answer'])->toBe('All done.');
        expect($status['blocked'])->toBeNull();
        expect($status)->not->toHaveKey('history');

        // `/messages` is the conversation without the tool turns.
        $messages = AgentApiTestSupport::decode(AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'messages',
            jsonRequest('GET', "/api/v1/agent-api/chats/{$chatId}/messages", [], ['Authorization' => 'Bearer ' . $plaintext]),
            [$chatId],
        ))['data']['messages'];

        expect(array_column($messages, 'role'))->toBe(['user', 'assistant']);
        expect($messages[1]['text'])->toBe('All done.');

        // `/transcript` is everything, including the tool call the messages
        // view omits.
        $transcript = AgentApiTestSupport::decode(AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'transcript',
            jsonRequest('GET', "/api/v1/agent-api/chats/{$chatId}/transcript", [], ['Authorization' => 'Bearer ' . $plaintext]),
            [$chatId],
        ))['data']['chat'];

        expect(array_column($transcript['tool_calls'], 'tool_name'))->toContain('stub_input');
        expect(array_map(static fn(array $h): string => $h['role'], $transcript['history']))
            ->toContain('tool');
        expect($transcript['totals'])->toHaveKey('input_tokens');

        // Now the sweep has something to deliver, and delivers it once.
        // The earlier sweep found the chat unsettled and deferred the row, so
        // its backoff has to elapse before it comes due again — which is what
        // the worker loop's repeated sweeps are for.
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
        // secret again.
        expect($http->header(0, 'X-Spora-Key-Id'))->toBe($key->id);
        expect($http->header(0, 'X-Spora-Signature'))->toBe(hash_hmac(
            'sha256',
            $http->header(0, 'X-Spora-Timestamp') . '.' . $http->body(0),
            $key->secret_hash,
        ));

        // A second sweep has nothing left to say.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);
        expect($http->requests)->toHaveCount(1);
    });

    it('delivers a second callback when an approved chat settles again', function (): void {
        $seed = AgentApiTestSupport::seedAgent('blocked');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'blocked');

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
        AgentApiTestSupport::enableTool($seed['agentId'], StubOutputTool::class);

        $start = AgentApiTestSupport::call($container, AgentApiChatController::class, 'store', AgentApiTestSupport::bearer(
            $plaintext,
            ['text' => 'Send it', 'callback_url' => 'https://hooks.example.test/spora'],
        ));

        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];
        $callback = AgentApiTestSupport::callbackFor($chatId);

        AgentApiTestSupport::runToCompletion($container, $chatId);

        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');

        // The caller can see what it has to decide, without the transcript.
        $status = AgentApiTestSupport::decode(AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'status',
            jsonRequest('GET', "/api/v1/agent-api/chats/{$chatId}/status", [], ['Authorization' => 'Bearer ' . $plaintext]),
            [$chatId],
        ))['data']['chat'];

        expect($status['blocked']['reason'])->toBe('PENDING_APPROVAL');
        expect($status['blocked']['tool_calls'][0]['name'])->toBe('stub_output');

        // Parking is a settle, so the receiver is told about it.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(1);
        expect(json_decode($http->body(0), true)['status'])->toBe('PENDING_APPROVAL');
        expect(json_decode($http->body(0), true)['blocked']['reason'])->toBe('PENDING_APPROVAL');

        $approve = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'approve',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/approve", [
                'decisions' => [[
                    'provider_call_id' => 'pc_block',
                    'decision' => 'approve',
                    'arguments' => ['value' => 'x'],
                ]],
            ], ['Authorization' => 'Bearer ' . $plaintext]),
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
});

describe('Agent API authorisation', function (): void {
    it('refuses a request with no key, even when a session cookie is present', function (): void {
        $seed = AgentApiTestSupport::seedAgent('noauth');
        simulateLoggedInSession($seed['userId'], 'agentapi-noauth@example.com');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'n1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats'),
        );

        expect($response->getStatusCode())->toBe(401);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('UNAUTHENTICATED');
    });

    it('answers a wrong secret and an unknown id identically', function (): void {
        $seed = AgentApiTestSupport::seedAgent('wrong');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'wrong');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'w1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $wrongSecret = substr($plaintext, 0, -1) . ($plaintext[-1] === 'A' ? 'B' : 'A');
        $unknownId = 'spk_' . str_repeat('0', 32) . '_' . substr($plaintext, strpos($plaintext, '_') + 1);

        $wrong = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $wrongSecret]),
        );

        $unknown = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $unknownId]),
        );

        expect($wrong->getStatusCode())->toBe(401);
        expect($unknown->getStatusCode())->toBe(401);
        expect(AgentApiTestSupport::decode($unknown))->toBe(AgentApiTestSupport::decode($wrong));
    });

    it('rate-limits a valid key and says for how long', function (): void {
        $seed = AgentApiTestSupport::seedAgent('oracle');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'oracle');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'o1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()], rateLimitMax: 3);

        $request = fn(): Request => jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $plaintext]);

        // Pinned to one wall-clock second: the ceiling used to deduplicate
        // same-second hits and count this burst as one request, so a test
        // that merely ran fast would pass against the broken limiter.
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        try {
            for ($i = 0; $i < 3; $i++) {
                expect(AgentApiTestSupport::call($container, AgentApiChatController::class, 'index', $request())->getStatusCode())->toBe(200);
            }

            $limited = AgentApiTestSupport::call($container, AgentApiChatController::class, 'index', $request());

            expect($limited->getStatusCode())->toBe(429);
            expect($limited->headers->get('Retry-After'))->not->toBeNull();
        } finally {
            Carbon::setTestNow();
        }
    });

    it('refuses more media ids than it will look up', function (): void {
        $seed = AgentApiTestSupport::seedAgent('oracle');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'oracle');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'o1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        // Each id costs an existence lookup before it is known to be this
        // agent's, so an uncapped list is an uncapped number of queries on an
        // authenticated route. Rejected, not truncated: a caller that lost
        // attachments silently cannot tell it from one that sent none.
        $tooMany = array_map(static fn(int $i): string => str_repeat('a', 16) . $i, range(1, 200));

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($plaintext, ['text' => 'look at these', 'media_ids' => $tooMany]),
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('never turns a bad credential into a 429, however often it is repeated', function (): void {
        $seed = AgentApiTestSupport::seedAgent('oracle');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'oracle');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'o1'));

        // A limit low enough that a handful of attempts would exhaust it.
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);
        $container->set(Config::class, new Config([
            'SPORA_AGENT_API' => '1',
            'SPORA_AGENT_API_RATE_LIMIT_MAX' => '2',
            'SPORA_LOG_LEVEL' => 'error',
        ]));

        $wrongSecret = substr($plaintext, 0, -1) . ($plaintext[-1] === 'A' ? 'B' : 'A');

        // Ten wrong secrets against a real key id. If the limiter ran before
        // the verify, some of these would answer 429 — a response a caller
        // could use to learn that the id exists. A leaked id is not secret:
        // every callback sends it in cleartext as `X-Spora-Key-Id`.
        for ($i = 0; $i < 10; $i++) {
            $response = AgentApiTestSupport::call(
                $container,
                AgentApiChatController::class,
                'index',
                jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $wrongSecret]),
            );

            expect($response->getStatusCode())->toBe(401);
        }

        // The real key still authenticates — a wrong-secret flood must not
        // lock out the legitimate integration.
        $valid = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $plaintext]),
        );

        expect($valid->getStatusCode())->toBe(200);
    });

    it('counts only authenticated requests against the rate limit', function (): void {
        $seed = AgentApiTestSupport::seedAgent('rl');
        [$keyRow, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'rl');
        $bucket = 'agentapi:' . $keyRow->id;

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'r1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);
        $container->set(Config::class, new Config([
            'SPORA_AGENT_API' => '1',
            'SPORA_AGENT_API_RATE_LIMIT_MAX' => '2',
            'SPORA_LOG_LEVEL' => 'error',
        ]));

        $send = fn(): Response => AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $plaintext]),
        );

        // One hit is recorded per call. `DbRateLimiter` cannot count a
        // second hit inside the same second — `ratelimit_hits` is keyed
        // `(key, hit_at)` at second resolution, so a same-second burst
        // collides, fails open, and goes uncounted. That is pre-existing and
        // affects `/tick` identically; see the backlog item
        // `2026-10-18-db-rate-limiter-same-second-collision.md`. What this
        // test pins is that a *rejected* request records no hit, which is the
        // half the Agent API owns.
        expect($send()->getStatusCode())->toBe(200);
        expect(RatelimitHit::where('key', $bucket)->count())->toBe(1);

        // A rejected request must not consume a slot, or an attacker could
        // lock a legitimate integration out simply by guessing at a key.
        $wrong = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], [
                'Authorization' => 'Bearer ' . substr($plaintext, 0, -1) . ($plaintext[-1] === 'A' ? 'B' : 'A'),
            ]),
        );

        expect($wrong->getStatusCode())->toBe(401);
        expect(RatelimitHit::where('key', $bucket)->count())->toBe(1);
    });

    it('stamps last_used_at when a key authenticates', function (): void {
        $seed = AgentApiTestSupport::seedAgent('lastused');
        [$keyRow, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'lastused');

        expect($keyRow->last_used_at)->toBeNull();

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'l1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $plaintext]),
        );

        // The column is what tells an operator which keys are still live
        // before revoking one; if nothing writes it the tab reads "Never"
        // forever and the field is decoration.
        expect($keyRow->fresh()->last_used_at)->not->toBeNull();
    });

    it('cannot see another agent\'s chat', function (): void {
        $mine = AgentApiTestSupport::seedAgent('mine');
        $theirs = AgentApiTestSupport::seedAgent('theirs');

        [, $myKey] = AgentApiTestSupport::mintKey($mine['agentId'], 'mine');
        [, $theirKey] = AgentApiTestSupport::mintKey($theirs['agentId'], 'theirs');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 's1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $theirChat = (int) AgentApiTestSupport::decode(AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($theirKey, ['text' => 'theirs'], 'POST'),
        ))['data']['chat']['id'];

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'status',
            jsonRequest('GET', "/api/v1/agent-api/chats/{$theirChat}/status", [], ['Authorization' => 'Bearer ' . $myKey]),
            [$theirChat],
        );

        expect($response->getStatusCode())->toBe(404);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('NOT_FOUND');

        // And it does not appear in the other key's listing either.
        $list = AgentApiTestSupport::decode(AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'index',
            jsonRequest('GET', '/api/v1/agent-api/chats', [], ['Authorization' => 'Bearer ' . $myKey]),
        ))['data']['chats'];

        expect($list)->toBe([]);
    });

    it('ignores an agent_id in the request body', function (): void {
        $mine = AgentApiTestSupport::seedAgent('bodymine');
        $theirs = AgentApiTestSupport::seedAgent('bodytheirs');
        [, $myKey] = AgentApiTestSupport::mintKey($mine['agentId'], 'bodymine');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'b1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($myKey, ['text' => 'hi', 'agent_id' => $theirs['agentId']]),
        );

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];

        expect((int) Task::findOrFail($chatId)->agent_id)->toBe($mine['agentId']);
    });

    it('refuses a callback_url that resolves inside the network', function (): void {
        $seed = AgentApiTestSupport::seedAgent('ssrf');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'ssrf');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'hi', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'x1'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($plaintext, [
                'text' => 'hi',
                'callback_url' => 'http://169.254.169.254/latest/meta-data',
            ]),
        );

        expect($response->getStatusCode())->toBe(422);
        expect(Task::where('user_prompt', 'hi')->count())->toBe(0);
    });
});

describe('Agent API callback delivery failures', function (): void {
    it('retries a refused callback with backoff and then gives up', function (): void {
        $seed = AgentApiTestSupport::seedAgent('retry');
        [, $plaintext] = AgentApiTestSupport::mintKey($seed['agentId'], 'retry');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'done', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'r1'));
        $http = new RecordingHttpClient(array_fill(0, 10, 500));
        $container = AgentApiTestSupport::container($llm, $http, [new StubInputTool()]);

        $start = AgentApiTestSupport::call($container, AgentApiChatController::class, 'store', AgentApiTestSupport::bearer(
            $plaintext,
            ['text' => 'hi', 'callback_url' => 'https://hooks.example.test/spora'],
        ));

        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];
        AgentApiTestSupport::runToCompletion($container, $chatId);

        $callback = AgentApiTestSupport::callbackFor($chatId);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            AgentApiTestSupport::skipBackoff((int) $callback->id);

            expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);

            $callback->refresh();
            expect((int) $callback->attempts)->toBe($attempt);
            expect($callback->delivered_status)->toBeNull();
            // Still owed, and deferred — the sweep must not busy-loop.
            expect($callback->pending_status)->toBe('COMPLETED');
            expect($callback->next_attempt_at->timestamp)->toBeGreaterThan(time());
        }

        // The sixth failure exhausts the budget and dead-letters the row.
        AgentApiTestSupport::skipBackoff((int) $callback->id);
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);

        $callback->refresh();
        expect((int) $callback->attempts)->toBe(6);
        expect($callback->pending_status)->toBeNull();
        expect($callback->next_attempt_at)->toBeNull();
        expect((int) $callback->last_status_code)->toBe(500);

        // A dead-lettered row is not picked up again.
        expect(AgentApiTestSupport::dispatchDue($container))->toBe(0);
        expect($http->requests)->toHaveCount(6);
    });
});
