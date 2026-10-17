<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Core\Config;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns public application configuration for the frontend.
 *
 * The SPA fetches this on every page reload (`src/stores/runtimeConfig.ts`).
 * All runtime feature flags must flow through here so the frontend never
 * reads from `import.meta.env` for a server-controlled gate — that path
 * silently diverges from production defaults.
 *
 * This controller names the flags the SPA reads and nothing else. Every
 * default belongs to {@see \Spora\Settings\SettingsRegistry}, which is also
 * what {@see Config} resolves them through — including the `filter_var`
 * coercion, so `'false'` from `.env` reads as false here exactly as it does
 * server-side.
 */
final class ConfigController
{
    public function __construct(
        private readonly Config $config,
        private readonly WorkerRuntimeMode $workerRuntimeMode = WorkerRuntimeMode::Server,
        private readonly int $tickLeaseSeconds = 600,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'allow_registration'     => $this->config->getBool('SPORA_ALLOW_REGISTRATION'),
            // The SPA uses this to decide whether non-admin users see a
            // "Create group" affordance on MyGroupsPage — the server
            // enforces the gate independently via the route middleware list
            // in {@see \Spora\Core\RouteDefinitions}.
            'allow_group_creation'  => $this->config->getBool('SPORA_ALLOW_GROUP_CREATION'),
            'plugin_install_enabled' => $this->config->getBool('SPORA_PLUGIN_INSTALL_ENABLED'),
            'plugin_catalog_enabled' => $this->config->getBool('SPORA_PLUGIN_CATALOG_ENABLED'),
            'worker_runtime_mode'    => $this->workerRuntimeMode->value,
            'client_worker'          => [
                'enabled'                       => $this->workerRuntimeMode === WorkerRuntimeMode::Client,
                'tick_endpoint'                 => '/api/v1/tasks/{taskId}/tick',
                'housekeeping_endpoint'         => '/api/v1/worker/housekeeping',
                'housekeeping_interval_seconds' => 300,
                'tick_interval_ms'              => 2000,
                'tick_lease_seconds'            => $this->tickLeaseSeconds,
            ],
        ]);
    }
}
