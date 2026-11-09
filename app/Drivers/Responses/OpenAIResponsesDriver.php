<?php

declare(strict_types=1);

namespace Spora\Drivers\Responses;

use Psr\Log\LoggerInterface;
use Spora\Drivers\AbstractCompatibleDriver;
use Spora\Drivers\DriverOptionsAwareInterface;
use Spora\Drivers\EffortLevel;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Speaks OpenAI's `/v1/responses` API.
 *
 * The Chat Completions driver cannot run a reasoning model that calls
 * tools: from GPT-5.4 the API rejects tools at any effort other than
 * `none`, and the model's own default is `medium`, so silence is a 400
 * too. The Responses API is the endpoint where a reasoning model does
 * both.
 */
#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the OpenAI Responses endpoint. Leave empty for local models.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.openai.com/v1).', required: false, default: 'https://api.openai.com/v1')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. gpt-6-astra, gpt-6.1-sol).', required: false, default: 'gpt-6-astra')]
#[ToolSetting(key: 'effort', label: 'Effort', type: 'select', default: '', description: 'Default reasoning effort. Empty = the model default (nothing is sent).', options: ['' => 'Model default', 'none' => 'None', 'minimal' => 'Minimal', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'xHigh', 'max' => 'Max'])]
#[ToolSetting(key: 'store', label: 'Store responses', type: 'toggle', default: false, description: "Store the response on the provider's servers — OpenAI retains it for at least 30 days. When off, Spora replays the encrypted reasoning itself and the provider keeps nothing.")]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class OpenAIResponsesDriver extends AbstractCompatibleDriver implements DriverOptionsAwareInterface
{
    private const PROVIDER_KEY = 'openai_responses';

    private readonly ResponsesRequestBuilder $requestBuilder;

    private readonly ResponsesResponseParser $responseParser;

    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?ResponsesDriverOptions $options = null,
    ) {
        parent::__construct(
            $apiKey,
            $model,
            $baseUrl,
            $httpClient,
            $logger,
            $timeout,
            $options?->supportsImageInput,
        );
        $this->requestBuilder = new ResponsesRequestBuilder(
            $apiKey,
            $model,
            $options !== null && $options->store,
        );
        $this->responseParser = new ResponsesResponseParser($logger);
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $settings
     */
    public static function makeFromSettings(array $args, array $settings): LLMDriverInterface
    {
        /** @var HttpClientInterface $httpClient */
        $httpClient = $args['httpClient'];

        $logger = $args['logger'] ?? null;
        $supportsImageInput = $args['supportsImageInput'] ?? null;

        return new self(
            apiKey: (string) ($args['apiKey'] ?? ''),
            model: (string) ($args['model'] ?? ''),
            baseUrl: (string) ($args['baseUrl'] ?? ''),
            httpClient: $httpClient,
            logger: $logger instanceof LoggerInterface ? $logger : null,
            timeout: isset($args['timeout']) ? (int) $args['timeout'] : null,
            options: new ResponsesDriverOptions(
                supportsImageInput: is_bool($supportsImageInput) ? $supportsImageInput : null,
                // The frontend round-trips a toggle as the string
                // "true" / "false", and a setting the operator never
                // touched must mean "off".
                store: array_key_exists('store', $settings)
                    && is_scalar($settings['store'])
                    && filter_var($settings['store'], FILTER_VALIDATE_BOOLEAN),
            ),
        );
    }

    /**
     * The pricing tag, which is not the protocol identifier:
     * `getName()` names the protocol (`openai_responses`), this names
     * the provider whose price rows the call is metered against.
     *
     * The tag is the one the operator's existing rows already use —
     * `openai_compatible`, the Chat Completions driver's name — so
     * pointing an agent at this driver with the same model keeps the
     * price it already has. A tag of its own would match no row, and a
     * call with no price row is unpriced: free, with no limits.
     *
     * `Usage::$provider` is a different vocabulary — the frontend's
     * provider union — and stays `openai` for both OpenAI protocols.
     */
    public function getProviderName(): string
    {
        return 'openai_compatible';
    }

    /**
     * The Responses API accepts every level the canonical scale has, and
     * the restriction that forces Chat Completions down to `none` when
     * tools are present does not apply here.
     *
     * @return array<string, string>
     */
    public function effortLevels(): array
    {
        return [
            EffortLevel::None->value    => EffortLevel::None->value,
            EffortLevel::Minimal->value => EffortLevel::Minimal->value,
            EffortLevel::Low->value     => EffortLevel::Low->value,
            EffortLevel::Medium->value  => EffortLevel::Medium->value,
            EffortLevel::High->value    => EffortLevel::High->value,
            EffortLevel::XHigh->value   => EffortLevel::XHigh->value,
            EffortLevel::Max->value     => EffortLevel::Max->value,
        ];
    }

    /**
     * The image-capable OpenAI families, as the Chat Completions driver
     * reads them: the `input_image` part is accepted by the same models
     * that accept `image_url`.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        $model = strtolower($this->model);
        if ($model === '' || $model === 'gpt-3.5-turbo' || $model === 'gpt-4' || $model === 'o1-mini') {
            return false;
        }

        return str_starts_with($model, 'gpt-4o')
            || str_starts_with($model, 'gpt-4-turbo')
            || str_starts_with($model, 'gpt-4-vision')
            || str_starts_with($model, 'gpt-4.1')
            || str_starts_with($model, 'gpt-5')
            || str_starts_with($model, 'gpt-6')
            || str_starts_with($model, 'o1')
            || str_starts_with($model, 'o3')
            || str_starts_with($model, 'o4')
            || str_starts_with($model, 'chatgpt-4o');
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $body = $this->requestBuilder->buildBody($request, $this->resolveEffort($request->effort));
        $url = rtrim($this->baseUrl, '/') . '/responses';

        $this->logger?->debug('LLM Request (OpenAI Responses)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->requestBuilder->buildHeaders(),
            'json' => $body,
            'timeout' => $this->timeout ?? 300,
        ]);

        $this->throwIfError($response);

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        $this->logger?->debug('LLM Response (OpenAI Responses)', ['status' => $response->getStatusCode(), 'data' => $data]);

        return $this->responseParser->parse(
            $data,
            ResponsesRequestBuilder::toolNameMap($request->tools),
        );
    }

    private function throwIfError(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new LLMRateLimitException('OpenAI rate limit exceeded (HTTP 429).');
        }

        if ($statusCode >= 500) {
            $body = $response->getContent(throw: false);
            throw new LLMRetryableException("OpenAI API error {$statusCode}: {$body}");
        }

        if ($statusCode >= 400) {
            $body = $response->getContent(throw: false);
            throw new LLMProviderException("OpenAI API error {$statusCode}: {$body}");
        }
    }

    public static function getName(): string
    {
        return self::PROVIDER_KEY;
    }

    public static function getDisplayName(): string
    {
        return 'OpenAI Responses';
    }
}
