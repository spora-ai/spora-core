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
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Model identifier (e.g. claude-3-5-sonnet-20241022, claude-3-opus).', required: false, default: 'claude-3-5-sonnet-20241022')]
#[ToolSetting(key: 'enable_prompt_caching', label: 'Enable prompt caching', type: 'toggle', default: true, description: 'Add `cache_control: ephemeral` breakpoints on the stable system and tool prefixes. Disable when the upstream proxy strips cache_control fields.')]
#[ToolSetting(key: 'temperature', label: 'Temperature', type: 'text', description: 'Sampling temperature (0.0–2.0). Lower is more deterministic.', required: false, default: '0.7', validation: '/^[0-2](\.[0-9]+)?$/')]
#[ToolSetting(key: 'thinking_budget', label: 'Thinking Budget (tokens)', type: 'text', description: 'Maximum tokens for extended thinking (Claude 3.7+).', required: false, validation: '/^[1-9][0-9]*$/')]
#[ToolSetting(key: 'timeout', label: 'Timeout (seconds)', type: 'text', description: 'HTTP timeout per request. Increase for slow models (e.g. local Ollama).', required: false, default: '300')]
final class AnthropicCompatibleDriver extends AbstractCompatibleDriver
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
        );
        $this->requestBuilder = new AnthropicRequestBuilder(
            apiKey: $apiKey,
            model: $model,
            enablePromptCaching: $options->enablePromptCaching ?? true,
            thinkingBudget: $options?->thinkingBudget,
        );
        $this->responseParser = new AnthropicResponseParser($logger);
    }

    /**
     * Claude 3 / 4 family models all accept image content blocks on the
     * Anthropic Messages API. Older models (Claude 2, Claude Instant)
     * did not — keep the allowlist conservative.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        return str_starts_with($this->model, 'claude-3-')
            || str_starts_with($this->model, 'claude-4-');
    }

    public function getProviderName(): string
    {
        return 'anthropic_compatible';
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $tools    = $this->requestBuilder->convertTools($request->tools);
        $messages = $this->requestBuilder->convertMessages($request->messages);
        $body     = $this->requestBuilder->buildBody($request, $tools, $messages);

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
