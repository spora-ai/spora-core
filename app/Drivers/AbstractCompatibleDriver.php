<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base for OpenAI/Anthropic compatible HTTP drivers.
 *
 * Centralises the shared constructor shape (api key, model, base URL,
 * HTTP client, logger, timeout) and the methods with identical bodies
 * across these drivers — `getModelName()` and `getDefaultTools()` —
 * so subclasses only override what actually differs.
 *
 * Subclasses MUST implement `getProviderName()`, `getName()`,
 * `getDisplayName()`, and `complete()`. They MAY extend the constructor
 * to add their own config (e.g. `temperature`, `thinkingBudget`).
 */
abstract class AbstractCompatibleDriver implements LLMDriverInterface, LLMDriverConfigInterface
{
    public function __construct(
        protected readonly string              $apiKey,
        protected readonly string              $model,
        protected readonly string              $baseUrl,
        protected readonly HttpClientInterface $httpClient,
        protected readonly ?LoggerInterface    $logger = null,
        protected readonly ?int                $timeout = null,
    ) {}

    public function getModelName(): string
    {
        return $this->model;
    }

    /** @return list<class-string> */
    public static function getDefaultTools(): array
    {
        return [];
    }
}
