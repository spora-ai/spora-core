<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[ToolSetting(key: 'supports_image_input', label: 'Allow images', type: 'toggle', default: false, description: 'When enabled, image attachments are forwarded to the LLM as image content blocks. Disable for non-vision endpoints to avoid API errors.')]
abstract class AbstractCompatibleDriver implements LLMDriverInterface, LLMDriverConfigInterface, EffortAwareDriverInterface
{
    /**
     * The levels the configuration names. Empty means the driver's own set
     * for the model applies.
     *
     * @var list<string>
     */
    protected readonly array $configuredEffortLevels;

    /**
     * @param list<string> $effortLevels The levels the configuration names.
     *        Empty means the driver's own set for the model applies.
     */
    public function __construct(
        protected readonly string              $apiKey,
        protected readonly string              $model,
        protected readonly string              $baseUrl,
        protected readonly HttpClientInterface $httpClient,
        protected readonly ?LoggerInterface    $logger = null,
        protected readonly ?int                $timeout = null,
        protected readonly ?bool               $supportsImageInput = null,
        array                                   $effortLevels = [],
        protected readonly ?bool               $supportsEffort = null,
    ) {
        $this->configuredEffortLevels = $effortLevels;
    }

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

    /**
     * The levels this driver can express, mapped to the provider's wire value.
     *
     * The operator's list wins outright: they know their endpoint, and
     * Spora has no business second-guessing them. An unset list keeps the
     * driver's own set for the model.
     *
     * `supportsEffort === null` means "not configured" and behaves as
     * enabled, so a row persisted before the toggle shipped keeps working.
     * `false` is the only value that disables.
     *
     * @return array<string, string>
     */
    public function effortLevels(): array
    {
        if ($this->supportsEffort === false) {
            return [];
        }

        if ($this->configuredEffortLevels !== []) {
            return $this->levelMap($this->configuredEffortLevels);
        }

        return $this->defaultEffortLevels();
    }

    /**
     * The levels the driver's model takes when the operator names none.
     *
     * @return array<string, string>
     */
    protected function defaultEffortLevels(): array
    {
        return [];
    }

    /**
     * The configured names as a name => wire value map. The wire value is
     * the name verbatim: every provider found sends the level unchanged.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    private function levelMap(array $names): array
    {
        $map = [];

        foreach ($names as $name) {
            $map[$name] ??= $name;
        }

        return $map;
    }

    /**
     * The provider value for a canonical effort level.
     *
     * `null` means the request carries no level — the model default, and
     * the driver sends no effort parameter at all.
     */
    protected function resolveEffort(?string $effort): ?string
    {
        if ($effort === null) {
            return null;
        }

        return $this->effortLevels()[$effort] ?? null;
    }

    /** @return list<class-string> */
    public static function getDefaultTools(): array
    {
        return [];
    }
}
