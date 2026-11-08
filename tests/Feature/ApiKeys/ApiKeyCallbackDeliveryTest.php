<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use BadMethodCallException;
use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Models\ApiCallback;
use Spora\Services\ApiKeys\ApiKeyCallbackDispatcher;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * What the worker checks before it posts a callback.
 *
 * A registration is a snapshot of two things that can change: the key's reach
 * over the agent, and the address the receiver's name answers with. Both are
 * re-checked at delivery, and a delivery that cannot be made is accounted for
 * like any other failure.
 */
describe('callback delivery checks', function (): void {
    /**
     * A group-scoped chat with a callback, settled and waiting for the sweep.
     *
     * @return array{0: \Psr\Container\ContainerInterface, 1: HttpClientInterface, 2: int, 3: array<string, mixed>}
     */
    function settledGroupChat(string $suffix, HttpClientInterface $http): array
    {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm(), $http);
        $group = AgentApiTestSupport::seedGroup($suffix);
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'callback');

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
                    'callback_url' => 'https://hooks.example.test/spora',
                ],
            ),
        );

        $chatId = (int) ScopedApiTestSupport::data($response)['chat']['id'];
        AgentApiTestSupport::runToCompletion($container, $chatId);

        return [$container, $http, $chatId, $group];
    }

    it('does not deliver a callback whose key no longer reaches the agent', function (): void {
        $http = new CallbackRecordingHttpClient();
        [$container, , $chatId, $group] = settledGroupChat('reach', $http);

        // The agent moves to the owner's own principal: the group key reaches
        // the group, and the agent is no longer in it.
        $target = createUserPrincipalPublic($group['userId']);
        (new PrincipalService(new PrincipalResolver()))
            ->transferAgent($group['agentIds'][0], $target, $group['userId']);

        expect($container->get(ApiKeyCallbackDispatcher::class)->dispatchDue())->toBe(0);
        expect($http->requests)->toBe([]);

        // The subscription goes with the access.
        expect(ApiCallback::where('chat_id', $chatId)->count())->toBe(0);
    });

    it('does not deliver a callback whose name now answers with a private address', function (): void {
        $http = new CallbackRecordingHttpClient();
        [$container, , $chatId] = settledGroupChat('rebind', $http);

        // The name the callback was registered under answers with a loopback
        // address now.
        ApiCallback::where('chat_id', $chatId)->update(['url' => 'https://localhost/spora']);

        expect($container->get(ApiKeyCallbackDispatcher::class)->dispatchDue())->toBe(0);
        expect($http->requests)->toBe([]);

        // The attempt is accounted for, so the row does not stay due for ever.
        $row = ApiCallback::where('chat_id', $chatId)->first();
        expect((int) $row->attempts)->toBe(1);
        expect($row->next_attempt_at->timestamp)->toBeGreaterThan(time());
    });

    it('counts a transport failure while the status is read as an attempt', function (): void {
        $http = new CallbackRecordingHttpClient(failOnStatus: true);
        [$container, , $chatId] = settledGroupChat('deferred', $http);

        expect($container->get(ApiKeyCallbackDispatcher::class)->dispatchDue())->toBe(0);

        // The row is left due and retried for ever if the failure is not
        // counted.
        $row = ApiCallback::where('chat_id', $chatId)->first();
        expect((int) $row->attempts)->toBe(1);
        expect($row->next_attempt_at->timestamp)->toBeGreaterThan(time());
    });

    it('records the status of a failed delivery without reading the body', function (): void {
        $http = new CallbackRecordingHttpClient(statuses: [500]);
        [$container, , $chatId] = settledGroupChat('body', $http);

        expect($container->get(ApiKeyCallbackDispatcher::class)->dispatchDue())->toBe(0);

        $row = ApiCallback::where('chat_id', $chatId)->first();
        expect((int) $row->last_status_code)->toBe(500);
        // The receiver's body is its own: a small gzipped answer can expand
        // into a large allocation in the worker before it is truncated.
        expect((string) $row->last_error)->toBe('HTTP 500');
    });
});

/**
 * The receiver of a callback, and the shape of its answer.
 */
final class CallbackRecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<int> */
    private array $statuses;

    public function __construct(array $statuses = [200], private readonly bool $failOnStatus = false)
    {
        $this->statuses = $statuses === [] ? [200] : $statuses;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $index = min(count($this->requests), count($this->statuses) - 1);
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new CallbackRecordingResponse($this->statuses[$index], $this->failOnStatus);
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new BadMethodCallException('Not needed here.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class CallbackRecordingResponse implements ResponseInterface
{
    public function __construct(private readonly int $status = 200, private readonly bool $failOnStatus = false) {}

    public function getStatusCode(): int
    {
        if ($this->failOnStatus) {
            throw new TransportException('The connection was reset.');
        }

        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    public function toArray(bool $throw = true): array
    {
        return ['error' => ['code' => 'DELIVERY_FAILED', 'message' => 'synthetic failure']];
    }

    public function cancel(): void {}

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}
