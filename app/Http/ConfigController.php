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
            'allow_registration'     => (bool) ($this->config['allow_registration']     ?? true),
            'plugin_install_enabled' => (bool) ($this->config['plugin_install_enabled'] ?? false),
            'plugin_catalog_enabled' => (bool) ($this->config['plugin_catalog_enabled'] ?? true),
        ]);
    }
}
