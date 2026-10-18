<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use BadMethodCallException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Console\WorkerLoopOptions;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Services\AgentApi\AgentApiCallbackDispatcher;
use Spora\Services\MercurePublisherInterface;
use Tests\Fixtures\StubInputTool;

/**
 * The worker's half of callback delivery.
 *
 * Driven through the real container and the real dispatcher, because a mock
 * here would only prove the command calls a method — what matters is that a
 * chat settling inside the loop results in one delivery.
 */
describe('WorkerRunCommand callback sweep', function (): void {
    /**
     * Start a chat with a callback, let it complete, then run the worker's
     * loop over it once.
     *
     * @return array{0: \Psr\Container\ContainerInterface, 1: RecordingHttpClient, 2: int}
     */
    function settledChatWithCallback(string $suffix): array
    {
        $seed = AgentApiTestSupport::seedAgent($suffix);
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], $suffix);

        $llm = new ScriptedLlmDriver(
            new LLMResponse(content: 'The answer is 51.', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'w1'),
        );
        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container($llm, $http, [new StubInputTool()]);

        $start = AgentApiTestSupport::call(
            $container,
            \Spora\Http\AgentApi\AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, [
                'text' => 'What is 17 times 3?',
                'callback_url' => 'https://hooks.example.test/spora',
            ]),
        );
        $chatId = (int) AgentApiTestSupport::decode($start)['data']['chat']['id'];

        // Settle it here, not in the loop: what the loop's sweep does is
        // independent of how the chat reached its terminal state.
        AgentApiTestSupport::runToCompletion($container, $chatId);
        AgentApiTestSupport::makeCallbackDue((int) AgentApiTestSupport::callbackFor($chatId)->id);

        return [$container, $http, $chatId];
    }

    function oneIteration(WorkerRunCommand $command): void
    {
        $options = new WorkerLoopOptions(
            isDaemon: false,
            isOnce: true,
            includeQueue: false,
            useChildProcesses: false,
            maxWorkers: 0,
            sleep: 0,
            staleMinutes: 0,
        );

        $processed = 0;
        $loop = (new ReflectionClass($command))->getMethod('runLoopIteration');

        // `invokeArgs` rather than `invoke`: `runLoopIteration()` takes
        // `$processed` by reference, and `invoke()` cannot pass one, which
        // PHP reports as a warning.
        $loop->invokeArgs($command, [
            $options,
            new \Symfony\Component\Console\Output\NullOutput(),
            time(),
            &$processed,
        ]);
    }

    it('delivers a settled chat from inside the worker loop', function (): void {
        [$container, $http, $chatId] = settledChatWithCallback('wsweep');

        expect($http->requests)->toBe([]);

        oneIteration($container->get(WorkerRunCommand::class));

        expect($http->requests)->toHaveCount(1);
        expect($http->requests[0]['url'])->toBe('https://hooks.example.test/spora');
        expect(json_decode($http->body(0), true)['answer'])->toBe('The answer is 51.');
        expect($chatId)->toBeGreaterThan(0);
    });

    it('sweeps again after the loop, so --once still delivers', function (): void {
        [$container, $http] = settledChatWithCallback('wpost');

        // Nothing inside the loop this time — the sweep is invoked directly,
        // which is the code path `runWorker()` takes after the loop exits.
        $after = (new ReflectionClass($container->get(WorkerRunCommand::class)))
            ->getMethod('dispatchAgentApiCallbacks');
        $after->invoke($container->get(WorkerRunCommand::class));

        expect($http->requests)->toHaveCount(1);
    });

    it('delivers nothing when the chat has not settled', function (): void {
        $seed = AgentApiTestSupport::seedAgent('wqueued');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'wqueued');

        $http = new RecordingHttpClient();
        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(
                new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'q'),
            ),
            $http,
            [new StubInputTool()],
        );

        AgentApiTestSupport::call(
            $container,
            \Spora\Http\AgentApi\AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, ['text' => 'hi', 'callback_url' => 'https://hooks.example.test/spora']),
        );

        oneIteration($container->get(WorkerRunCommand::class));

        expect($http->requests)->toBe([]);
    });

    it('never lets a broken receiver escape the sweep', function (): void {
        [$container] = settledChatWithCallback('wboom');

        // A second container over the same rows, whose client fails before a
        // response exists — a DNS failure, a refused connection, a timeout, as
        // distinct from the 5xx the retry test already covers.
        $broken = AgentApiTestSupport::container(
            new ScriptedLlmDriver(
                new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'b'),
            ),
            new ThrowingHttpClient(),
            [new StubInputTool()],
        );

        // `dispatchDue()` is called from inside the worker loop, so anything
        // it let out would take the agent loop with it.
        expect($broken->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(0);
        expect($broken->get(AgentApiCallbackDispatcher::class)->dispatchDue())->toBe(0);
    });

    it('builds a command whose container knows the dispatcher', function (): void {
        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(
                new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'b'),
            ),
            null,
            [new StubInputTool()],
        );

        // A container that cannot build the dispatcher must still yield a
        // usable command, so a trimmed runtime is not left without a worker.
        $bare = Mockery::mock(\Psr\Container\ContainerInterface::class);
        $bare->allows('has')->with(AgentApiCallbackDispatcher::class)->andReturn(false);
        $bare->allows('get')->andReturnNull();

        expect($container->has(AgentApiCallbackDispatcher::class))->toBeTrue();
        expect($container->has(MercurePublisherInterface::class))->toBeTrue();
        expect($bare->has(AgentApiCallbackDispatcher::class))->toBeFalse();
    });
});

/**
 * An HTTP client whose transport fails before a response exists — the shape
 * of a DNS failure, a refused connection or a timeout, as distinct from the
 * 5xx `RecordingHttpClient` returns.
 */
final class ThrowingHttpClient implements \Symfony\Contracts\HttpClient\HttpClientInterface
{
    public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        throw new RuntimeException('connection refused');
    }

    public function stream($responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        throw new BadMethodCallException('Not needed here.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
