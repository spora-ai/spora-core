<?php

declare(strict_types=1);

namespace Spora\Speech;

/**
 * Discovers every plugin-contributed {@see SpeechToTextProviderInterface}
 * and picks the first configured one for the transcribe endpoint.
 *
 * Selection model is **first-configured wins** — the order of providers
 * in the constructor is the order of preference. The container defines
 * that order by listing the merged class entries (core list +
 * `PluginLoader::speechToTextProviderClasses()`); per-class load order
 * is FIFO by plugin manifest discovery.
 *
 * Per-agent provider override is **out of scope** for v1. The registry
 * exposes {@see describe()} so a future per-agent picker UI can read the
 * available providers without changing this contract.
 */
final readonly class SpeechToTextRegistry
{
    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(private array $providers) {}

    /**
     * @return list<SpeechToTextProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * First provider whose `isConfigured()` returns true, or null when no
     * provider reports itself as ready. The transcribe controller
     * translates `null` into HTTP 503 `SPEECH_PROVIDER_UNAVAILABLE`.
     */
    public function configuredProvider(): ?SpeechToTextProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Wire shape backing {@see \Spora\Http\SpeechCapabilityController::index()}.
     *
     * @return list<array{name: string, display_name: string, configured: bool}>
     */
    public function describe(): array
    {
        $rows = [];
        foreach ($this->providers as $provider) {
            $rows[] = [
                'name'         => $provider->getName(),
                'display_name' => $provider->getDisplayName(),
                'configured'   => $provider->isConfigured(),
            ];
        }
        return $rows;
    }
}
