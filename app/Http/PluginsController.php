<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\PluginsService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Lists installed plugins with their metadata and migration status.
 *
 * Read-only inventory for v1 — there are no POST/PUT/DELETE endpoints here.
 * Operators rely on the existing spora:install CLI to apply plugin migrations.
 */
final class PluginsController
{
    public function __construct(
        private readonly PluginsService $pluginsService,
    ) {}

    /**
     * GET /api/v1/plugins
     */
    public function index(): JsonResponse
    {
        $plugins = $this->pluginsService->listPlugins();

        return new JsonResponse(['data' => ['plugins' => $plugins]]);
    }
}
