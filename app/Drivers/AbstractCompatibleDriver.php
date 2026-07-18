<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[ToolSetting(key: 'supports_image_input', label: 'Allow images', type: 'toggle', default: false, description: 'When enabled, image attachments are forwarded to the LLM as image content blocks. Disable for non-vision endpoints to avoid API errors.')]
abstract class AbstractCompatibleDriver implements LLMDriverInterface, LLMDriverConfigInterface
{
    public function __construct(
        protected readonly string              $apiKey,
        protected readonly string              $model,
        protected readonly string              $baseUrl,
        protected readonly HttpClientInterface $httpClient,
        protected readonly ?LoggerInterface    $logger = null,
        protected readonly ?int                $timeout = null,
        protected readonly ?bool               $supportsImageInput = null,
    ) {}

    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Operator-controlled image-input capability. When the LLM driver config
     * explicitly sets the toggle (true or false) the operator's choice wins.
     * A null value falls back to {@see modelBasedSupportsImageInput()} for
     * rows persisted before the toggle shipped — legacy LLMDriverConfiguration
     * rows without the key keep their previous heuristic-driven behaviour.
     */
    public function supportsImageInput(): bool
    {
        return $this->supportsImageInput ?? $this->modelBasedSupportsImageInput();
    }

    /**
     * Default heuristic for {@see supportsImageInput()} when the operator has
     * not set the toggle. Subclasses override per model family.
     */
    protected function modelBasedSupportsImageInput(): bool
    {
        return false;
    }

    /** @return list<class-string> */
    public static function getDefaultTools(): array
    {
        return [];
    }
}
