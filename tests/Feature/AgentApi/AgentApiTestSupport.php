<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use BadMethodCallException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AgentApiKeyIssuer;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Drivers\DriverFactory;
use Spora\Http\Middleware\AgentApiKeyMiddleware;
use Spora\Models\Agent;
use Spora\Models\AgentApiCallback;
use Spora\Models\AgentApiKey;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentApi\AgentApiCallbackDispatcher;
use Spora\Services\AgentApi\AgentApiChatProjection;
use Spora\Services\MediaArchive\MediaArchiveService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared scaffolding for the Agent API tests.
 *
 * Static methods on a class rather than functions in a test file: Pest loads
 * one file per worker, so a function defined in `AgentApiEndToEndTest.php` is
 * invisible to `AgentApiRouteGateTest.php`. Only `CrossFileTestHelpers.php` is
 * in `composer.json#autoload-dev.files`, so a new global-function file would
 * not be loaded everywhere either.
 */
final class AgentApiTestSupport
{
    /**
     * The whole container with three overrides: the feature flag on, a
     * scripted LLM, and a recording HTTP client for callbacks.
     *
     * The overrides are `set()` after `build()`, which PHP-DI allows because
     * it resolves lazily — so nothing may be `get()`-ed before this returns.
     *
     * @param list<\Spora\Tools\ToolInterface> $tools
     */
    public static function container(
        ScriptedLlmDriver $llm,
        ?RecordingHttpClient $http = null,
        array $tools = [],
    ): \Psr\Container\ContainerInterface {
        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($llm);

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(ContainerDefinitions::all());
        // `Kernel` injects a built `PluginLoader` before `build()`; the raw
        // definition list has no way to construct one, because
        // `$pluginDirectories` has no default. An empty loader means "no
        // plugins", which is what an Agent API test wants.
        $builder->addDefinitions([\Spora\Plugins\PluginLoader::class => new \Spora\Plugins\PluginLoader([], null)]);
        $container = $builder->build();

        $container->set(Config::class, new Config([
            'SPORA_AGENT_API' => '1',
            'SPORA_AGENT_API_RATE_LIMIT_MAX' => '1000',
            'SPORA_APP_ENV' => 'testing',
            // The logger factory does `constant(Level::class . '::' . $level)`
            // against this, so it has to be present or resolution throws.
            'SPORA_LOG_LEVEL' => 'error',
        ]));
        $container->set(DriverFactory::class, $factory);
        $container->set(HttpClientInterface::class, $http ?? new RecordingHttpClient());
        $container->set(WorkerRuntimeMode::class, WorkerRuntimeMode::Server);

        // The container's own orchestrator builds its tool list from
        // `InstalledToolClasses`, which reflects over the production
        // `app/Tools` registry — the stub tools a test scripts with are not
        // in it. So the orchestrator is replaced rather than configured.
        $container->set(OrchestratorInterface::class, new Orchestrator(
            $factory,
            new OrchestratorConfig(toolInstances: $tools),
        ));

        return $container;
    }

    /**
     * Enable a tool for an agent, which the orchestrator requires before it
     * will execute a call to it.
     *
     * @param class-string $toolClass
     */
    public static function enableTool(int $agentId, string $toolClass): void
    {
        $attribute = (new ReflectionClass($toolClass))->getAttributes(\Spora\Tools\Attributes\Tool::class)[0]
            ->newInstance();

        \Spora\Models\AgentTool::create([
            'agent_id' => $agentId,
            'tool_class' => $toolClass,
            'tool_name' => $attribute->name,
        ]);
    }

    /**
     * A user with a personal principal and an agent wired to a global LLM
     * config, which is the minimum for `startTask` to resolve ownership.
     *
     * @return array{userId: int, principalId: int, agentId: int}
     */
    public static function seedAgent(string $suffix = 'a'): array
    {
        $auth = bootAuthLayer();
        $userId = $auth->register("agentapi-{$suffix}@example.com", 'Password1!', "AgentApi {$suffix}");

        $principalId = createUserPrincipalPublic($userId);
        $llm = self::llmConfig($suffix);

        $agent = Agent::create([
            'principal_id' => $principalId,
            'name' => "Agent API {$suffix}",
            'llm_driver_config_id' => $llm->id,
            'max_steps' => 10,
            'is_active' => true,
        ]);

        return ['userId' => $userId, 'principalId' => $principalId, 'agentId' => (int) $agent->id];
    }

    public static function llmConfig(string $suffix = 'a'): LLMDriverConfiguration
    {
        return createTestGlobalConfig(
            "AgentApi LLM {$suffix}",
            \Spora\Drivers\OpenAICompatibleDriver::class,
            ['api_key' => 'test'],
            true,
        );
    }

    /**
     * @return array{0: AgentApiKey, 1: string} the row and the plaintext key
     */
    public static function mintKey(int $agentId, string $name = 'ci'): array
    {
        return (new AgentApiKeyIssuer())->issue($agentId, $name);
    }

    /**
     * Send a request through a controller with the real bearer middleware.
     *
     * The middleware is what the test is exercising, so it runs here rather
     * than being bypassed; the context it puts on the request is read off the
     * request, exactly as a controller does it in production.
     *
     * `$pathVars` are bound the way `Router::handleFound()` binds them, and
     * `$args` are the controller method's remaining parameters — the two are
     * kept separate because the production router matches them by *name*.
     */
    public static function call(
        \Psr\Container\ContainerInterface $container,
        string $controllerClass,
        string $method,
        Request $request,
        array $args = [],
        ?AgentApiKeyMiddleware $middleware = null,
    ): Response {
        $middleware ??= $container->get(AgentApiKeyMiddleware::class);

        /** @var Response $response */
        $response = $middleware->handle(
            $request,
            static fn(Request $incoming): Response => $container->get($controllerClass)->{$method}($incoming, ...$args),
        );

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function bearer(string $key, array $body = [], string $method = 'POST'): Request
    {
        return jsonRequest($method, '/api/v1/agent-api/chats', $body, ['Authorization' => 'Bearer ' . $key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(Response $response): array
    {
        $body = $response->getContent();

        return $body === '' ? [] : (json_decode($body, true) ?: []);
    }

    /**
     * Make a callback row's backoff elapsed, the way the worker loop would
     * eventually see it. There is no clock double in the suite, so this is the
     * same fast-into-the-past UPDATE the retry tests use.
     */
    public static function makeCallbackDue(int $callbackId): void
    {
        Capsule::table('agent_api_callbacks')
            ->where('id', $callbackId)
            ->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    }

    /**
     * Record `next_attempt_at` as due and run one sweep.
     *
     * Called after a state change rather than before, because the recorder
     * writes the row with `next_attempt_at = null` — nothing is owed until
     * the chat settles, and it is the sweep that must notice.
     */
    public static function dispatchDue(\Psr\Container\ContainerInterface $container): int
    {
        /** @var AgentApiCallbackDispatcher $dispatcher */
        $dispatcher = $container->get(AgentApiCallbackDispatcher::class);

        return $dispatcher->dispatchDue();
    }

    /**
     * One row per chat, so a test that starts a chat with a callback can find
     * it back without guessing an id.
     */
    public static function callbackFor(int $chatId): AgentApiCallback
    {
        $row = AgentApiCallback::where('chat_id', $chatId)->first();

        if ($row === null) {
            throw new RuntimeException("No callback recorded for chat {$chatId}.");
        }

        return $row;
    }

    /**
     * Drive a chat the way the worker daemon does: claim the QUEUED row, tick
     * until it stops being drivable. Used instead of a single `claimAndTick`
     * so a tool-call turn and the following answer turn both happen.
     */
    public static function runToCompletion(\Psr\Container\ContainerInterface $container, int $chatId): void
    {
        $policy = new \Spora\Agents\TaskLifecyclePolicy();

        for ($i = 0; $i < 8; $i++) {
            $status = (string) \Spora\Models\Task::where('id', $chatId)->value('status');

            if ($policy->isTerminal($status) || $policy->isQuiescent($status)) {
                return;
            }

            Capsule::table('tasks')->where('id', $chatId)->where('status', 'QUEUED')->update(['status' => 'RUNNING']);

            $container->get(OrchestratorInterface::class)->tick($chatId);
        }
    }

    public static function newOrchestrator(ScriptedLlmDriver $llm): Orchestrator
    {
        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($llm);

        return new Orchestrator($factory, new OrchestratorConfig(toolInstances: []));
    }

    public static function mediaArchive(\Psr\Container\ContainerInterface $container): MediaArchiveService
    {
        return $container->get(MediaArchiveService::class);
    }

    public static function projection(): AgentApiChatProjection
    {
        return new AgentApiChatProjection();
    }

    public static function auth(): AuthService
    {
        return bootAuthLayer();
    }
}

/**
 * Captures every outbound request the callback dispatcher makes.
 *
 * `MockHttpClient` is not used because the signature has to be verifiable
 * against the key's stored digest, which means the test needs the raw body.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<int> */
    private array $statuses;

    /**
     * @param list<int> $statuses consumed one per request; the last repeats
     */
    public function __construct(array $statuses = [200])
    {
        $this->statuses = $statuses === [] ? [200] : $statuses;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $index = min(count($this->requests), count($this->statuses) - 1);
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new RecordingResponse($this->statuses[$index]);
    }

    public function stream($responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        throw new BadMethodCallException('Not needed by the Agent API tests.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    public function reset(): void
    {
        $this->requests = [];
    }

    public function body(int $index = 0): string
    {
        return (string) ($this->requests[$index]['options']['body'] ?? '');
    }

    public function header(int $index, string $name): ?string
    {
        $headers = $this->requests[$index]['options']['headers'] ?? [];

        return is_array($headers) ? ($headers[$name] ?? null) : null;
    }
}

final class RecordingResponse implements ResponseInterface
{
    public function __construct(private readonly int $status = 200) {}

    public function getStatusCode(): int
    {
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
