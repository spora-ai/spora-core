<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;

/**
 * The configuration an agent actually runs with, as the API resource,
 * plus the tier it came from.
 *
 * The cascade itself lives in {@see LLMConfigPreferences}; this maps the
 * resolved row through the resource DTO so the caller — the HTTP layer —
 * needs neither the schema inspector nor the encryption layer.
 *
 * Split out of {@see LLMConfigService}, which sat on the SonarCloud
 * S1448 20-method ceiling and would have crossed it with the mapping
 * added — the same answer {@see LlmConfigValidator} gives for the
 * controller.
 */
final class EffectiveLLMConfigResolver
{
    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly LLMConfigServiceInterface $llmConfigService,
    ) {}

    /**
     * @return array{config: ?array<string, mixed>, source: string}
     */
    public function resolve(Agent $agent): array
    {
        $resolved = $this->preferences->resolveEffectiveConfigForAgent($agent);
        $config = $resolved['config'];

        return [
            'config' => $config !== null ? $this->llmConfigService->configResource($config) : null,
            'source' => $resolved['source'],
        ];
    }
}
