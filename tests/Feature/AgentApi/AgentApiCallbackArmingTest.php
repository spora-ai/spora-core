<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use RuntimeException;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\AgentApi\AgentApiChatActionController;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Models\AgentApiCallback;
use Spora\Models\Task;
use Spora\Services\AgentApi\AgentApiCallbackDispatcher;
use Tests\Fixtures\StubOutputTool;

/**
 * The callback arm/disarm state machine, on its own.
 *
 * Every case here exists because the corresponding bug shipped once: the
 * recorder wrote an unarmed row and the sweep could not see it, and a
 * successful delivery disarmed a row that still had a second settlement
 * coming. Both passed the suite because every test force-wrote
 * `next_attempt_at` before sweeping. These do not.
 */
describe('Agent API callback arming', function (): void {
    /**
     * A chat with a callback row, driven to `$status`.
     *
     * @return array{0: \Psr\Container\ContainerInterface, 1: RecordingHttpClient, 2: int, 3: AgentApiCallback, 4: string}
     */
    function chatWithCallback(string $suffix, bool $blockOnTool = false): array
    {
        $seed = AgentApiTestSupport::seedAgent($suffix);
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], $suffix);

        $llm = $blockOnTool
            ? new ScriptedLlmDriver(
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall('pc_a', 'stub_output', ['value' => 'x'])],
                    inputTokens: 1,
                    outputTokens: 1,
                    completionId: 'a1',
                ),
                new LLMResponse(content: 'done', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'a2'),
            )
            : new ScriptedLlmDriver(
                new LLMResponse(content: 'done', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'a1'),
            );

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubOutputTool()]);

        if ($blockOnTool) {
            AgentApiTestSupport::enableTool($seed['agentId'], StubOutputTool::class);
        }

        $start = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, [
                'text' => 'hi',
                'callback_url' => 'https://hooks.example.test/spora',
            ]),
        );

        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];

        return [$container, $http, $chatId, AgentApiTestSupport::callbackFor($chatId), $key];
    }

    it('arms the row at creation, while the chat is still queued', function (): void {
        [, , , $callback] = chatWithCallback('arm1');

        // The sweep selects `next_attempt_at IS NOT NULL`, so an unarmed row
        // is invisible to it forever. Nothing else ever arms it.
        expect($callback->next_attempt_at)->not->toBeNull();
        expect((string) Task::findOrFail($callback->chat_id)->status)->toBe('QUEUED');
    });

    it('delivers once a queued chat completes, with no test-side setup', function (): void {
        [$container, $http, $chatId] = chatWithCallback('arm2');

        AgentApiTestSupport::runToCompletion($container, $chatId);

        // No skipBackoff(): the row was armed at creation and has not been
        // deferred by an earlier sweep.
        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(1);
        expect($http->requests)->toHaveCount(1);
        expect(json_decode($http->body(0), true)['status'])->toBe('COMPLETED');
    });

    it('defers an unsettled row instead of leaving it due', function (): void {
        [$container, $http, , $callback] = chatWithCallback('arm3');

        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(0);
        expect($http->requests)->toBe([]);

        // Deferring is not housekeeping — it is what stops the sweep's
        // `ORDER BY id LIMIT 25` from being filled by chats that are still
        // running, which would starve every settled chat behind them.
        $callback->refresh();
        expect($callback->next_attempt_at->timestamp)->toBeGreaterThan(time());
    });

    it('disarms a row once a terminal status has been delivered', function (): void {
        [$container, $http, $chatId, $callback] = chatWithCallback('arm4');

        AgentApiTestSupport::runToCompletion($container, $chatId);
        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(1);

        // COMPLETED cannot change, so the row must stop being read — a
        // delivered terminal row that stays armed is a permanent query.
        $callback->refresh();
        expect($callback->delivered_status)->toBe('COMPLETED');
        expect($callback->next_attempt_at)->toBeNull();

        // No skipBackoff() here: the disarm is the subject of the assertion,
        // and the sweep should not even reach the row.
        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(0);
        expect($http->requests)->toHaveCount(1);
    });

    it('keeps watching a blocked row so a second settlement is delivered', function (): void {
        [$container, $http, $chatId, $callback, $key] = chatWithCallback('arm5', blockOnTool: true);

        AgentApiTestSupport::runToCompletion($container, $chatId);
        expect(Task::findOrFail($chatId)->status)->toBe('PENDING_APPROVAL');

        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(1);
        expect(json_decode($http->body(0), true)['status'])->toBe('PENDING_APPROVAL');

        // A blocked chat can be approved, so the row must stay armed. This is
        // the assertion that a delivery-time disarm breaks.
        $callback->refresh();
        expect($callback->next_attempt_at)->not->toBeNull();

        $approve = AgentApiTestSupport::call(
            $container,
            AgentApiChatActionController::class,
            'approve',
            jsonRequest('POST', "/api/v1/agent-api/chats/{$chatId}/approve", [
                'decisions' => [[
                    'provider_call_id' => 'pc_a',
                    'decision' => 'approve',
                    'arguments' => ['value' => 'x'],
                ]],
            ], ['Authorization' => 'Bearer ' . $key]),
            [$chatId],
        );
        expect($approve->getStatusCode())->toBe(202);

        AgentApiTestSupport::runToCompletion($container, $chatId);
        AgentApiTestSupport::skipBackoff((int) $callback->id);

        expect($container->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(1);
        expect($http->requests)->toHaveCount(2);
        expect(json_decode($http->body(1), true)['status'])->toBe('COMPLETED');
    });

    it('refuses to arm a row through the backoff helper', function (): void {
        // The helper that masked this bug must itself fail when used to arm,
        // so the masking cannot come back.
        expect(fn() => AgentApiTestSupport::skipBackoff(999999))
            ->toThrow(RuntimeException::class);
    });
});
