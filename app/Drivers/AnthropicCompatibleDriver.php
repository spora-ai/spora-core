<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Drivers\Anthropic\AnthropicRequestBuilder;
use Spora\Drivers\Anthropic\AnthropicResponseParser;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', description: 'API key for the Anthropic-compatible endpoint. Leave empty for local models.', required: false)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Base URL of the API endpoint (e.g. https://api.anthropic.com).', required: false, default: 'https://api.anthropic.com')]
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. claude-sonnet-5-5, claude-opus-4-6).', required: false, default: 'claude-sonnet-5-5')]
#[ToolSetting(key: 'enable_prompt_caching', label: 'Enable prompt caching', type: 'toggle', default: true, description: 'Add `cache_control: ephemeral` breakpoints on the stable system and tool prefixes. Disable when the upstream proxy strips cache_control fields.')]
#[ToolSetting(key: 'effort', label: 'Effort', type: 'text', default: '', description: 'Default reasoning effort. Empty = the model default (nothing is sent). A level this endpoint does not offer is refused.')]
#[ToolSetting(key: 'effort_levels', label: 'Effort levels', type: 'textarea', default: '', description: 'Comma-separated list of the reasoning-effort levels this endpoint accepts. Empty = the driver\'s own set. A level the endpoint does not take is refused.')]
#[ToolSetting(key: 'supports_effort', label: 'Supports reasoning effort', type: 'toggle', default: true, description: 'Turn this off for an endpoint with no reasoning-effort parameter at all — the agent then has no effort picker and any effort sent is refused.')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class AnthropicCompatibleDriver extends AbstractCompatibleDriver implements DriverOptionsAwareInterface
{
    private const PROVIDER_KEY = 'anthropic_compatible';

    private readonly AnthropicRequestBuilder $requestBuilder;

    private readonly AnthropicResponseParser $responseParser;

    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        ?LoggerInterface    $logger = null,
        ?int                $timeout = null,
        ?AnthropicDriverOptions $options = null,
    ) {
        parent::__construct(
            $apiKey,
            $model,
            $baseUrl,
            $httpClient,
            $logger,
            $timeout,
            $options?->supportsImageInput,
            $options !== null ? $options->configuredEffortLevels : [],
            $options?->supportsEffort,
        );
        $this->requestBuilder = new AnthropicRequestBuilder(
            apiKey: $apiKey,
            model: $model,
            enablePromptCaching: $options->enablePromptCaching ?? true,
        );
        $this->responseParser = new AnthropicResponseParser($logger);
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
        $effortLevels = $args['effortLevels'] ?? [];

        return new self(
            apiKey: (string) ($args['apiKey'] ?? ''),
            model: (string) ($args['model'] ?? ''),
            baseUrl: (string) ($args['baseUrl'] ?? ''),
            httpClient: $httpClient,
            logger: $logger instanceof LoggerInterface ? $logger : null,
            timeout: isset($args['timeout']) ? (int) $args['timeout'] : null,
            options: new AnthropicDriverOptions(
                supportsImageInput: is_bool($supportsImageInput) ? $supportsImageInput : null,
                enablePromptCaching: !array_key_exists('enable_prompt_caching', $settings)
                    || filter_var($settings['enable_prompt_caching'], FILTER_VALIDATE_BOOLEAN),
                configuredEffortLevels: is_array($effortLevels) ? array_values($effortLevels) : [],
                supportsEffort: isset($args['supportsEffort']) && is_bool($args['supportsEffort'])
                    ? $args['supportsEffort']
                    : null,
            ),
        );
    }

    /**
     * Every Claude generation from 3 onwards accepts image content blocks
     * on the Anthropic Messages API. Claude 2 and the Instant models do
     * not, and a name that is not a Claude id is not answered for.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        return ModelFamily::claudeSupportsImageInput($this->model);
    }

    /**
     * The levels the model takes.
     *
     * Anthropic spells every level the way the canonical scale does and
     * offers no `none` or `minimal` — the model thinks or it does not.
     * Which of the five a model takes is the model's own property, so
     * the question is asked in {@see ModelFamily}: Sonnet 4.5 and Haiku
     * 4.5 have no effort at all, Opus 4.5 has no `max`, and Opus 4.6
     * and Sonnet 4.6 have no `xhigh`.
     *
     * @return array<string, string>
     */
    protected function defaultEffortLevels(): array
    {
        $levels = [];
        foreach (ModelFamily::claudeEffortLevels($this->model) as $level) {
            $levels[$level->value] = $level->value;
        }

        return $levels;
    }

    public function getProviderName(): string
    {
        return 'anthropic_compatible';
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $tools    = $this->requestBuilder->convertTools($request->tools);
        $messages = $this->requestBuilder->convertMessages($request->messages);
        $body     = $this->requestBuilder->buildBody(
            $request,
            $tools,
            $messages,
            $this->resolveEffort($request->effort),
        );

        $url     = rtrim($this->baseUrl, '/') . '/v1/messages';
        $headers = $this->requestBuilder->buildHeaders();
        $this->logger?->debug('LLM Request (Anthropic)', ['url' => $url, 'payload' => $body]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'json'    => $body,
            'timeout' => $this->timeout ?? 300,
        ]);

        $this->throwIfErrorResponse($response);

        return $this->responseParser->parse($response);
    }

    private function throwIfErrorResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 429) {
            throw new LLMRateLimitException('Anthropic rate limit exceeded (HTTP 429).');
        }

        if ($statusCode >= 500) {
            $rawBody = $response->getContent(throw: false);
            throw new LLMRetryableException("Anthropic API error {$statusCode}: {$rawBody}");
        }

        if ($statusCode >= 400) {
            $rawBody = $response->getContent(throw: false);
            throw new LLMProviderException("Anthropic API error {$statusCode}: {$rawBody}");
        }
    }

    public static function getName(): string
    {
        return self::PROVIDER_KEY;
    }

    public static function getDisplayName(): string
    {
        return 'Anthropic Compatible';
    }
}
