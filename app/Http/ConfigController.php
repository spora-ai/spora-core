<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns public application configuration for the frontend.
 *
 * The SPA fetches this on every page reload (`src/stores/runtimeConfig.ts`).
 * All runtime feature flags must flow through here so the frontend never
 * reads from `import.meta.env` for a server-controlled gate — that path
 * silently diverges from production defaults.
 *
 * The flag defaults mirror `app/Core/ContainerDefinitions.php`:
 *   - allow_registration     defaults to true   (matches config['allow_registration'])
 *   - plugin_install_enabled defaults to false  (admin gate; safer to fail closed)
 *   - plugin_catalog_enabled defaults to true   (read-only browse UI)
 */
final class ConfigController
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'allow_registration'     => $this->boolFlag('allow_registration', true),
            'plugin_install_enabled' => $this->boolFlag('plugin_install_enabled', false),
            'plugin_catalog_enabled' => $this->boolFlag('plugin_catalog_enabled', true),
        ]);
    }

    /**
     * Resolve a boolean runtime flag from `$this->config`.
     *
     * Mirrors the env-overlay semantics in `app/Core/ContainerDefinitions.php`
     * (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`) so the SPA sees the same
     * truthiness as the server-side resolvers. A naive `(bool)` cast would
     * treat any non-empty string — including `'false'` — as `true`.
     *
     * Accepted truthy values: `true`, `1`, `'1'`, `'true'`, `'on'`, `'yes'`.
     * Accepted falsy values: `false`, `0`, `'0'`, `'false'`, `'off'`, `'no'`,
     * and `null` / missing (which falls back to `$default`).
     */
    private function boolFlag(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->config) || $this->config[$key] === null) {
            return $default;
        }
        $value = $this->config[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return $default;
    }
}
