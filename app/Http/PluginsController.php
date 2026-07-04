<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\PluginCatalogService;
use Spora\Services\PluginsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
        private readonly ?PluginCatalogService $pluginCatalog = null,
        private readonly bool $pluginCatalogEnabled = true,
    ) {}

    /**
     * GET /api/v1/plugins
     */
    public function index(): JsonResponse
    {
        $plugins = $this->pluginsService->listPlugins();

        return new JsonResponse(['data' => ['plugins' => $plugins]]);
    }

    /**
     * GET /api/v1/plugins/catalog?q={query}
     *
     * Browse the Packagist catalog of Spora plugins. The query is optional —
     * an empty string lists every package with type === 'spora-plugin'.
     *
     * When Spora_PLUGIN_CATALOG_ENABLED=false (default: true), the route returns
     * 404 so the navbar item hides cleanly without a per-render feature check.
     */
    public function catalog(Request $request): JsonResponse
    {
        if (!$this->pluginCatalogEnabled) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Plugin catalog is disabled.']],
                404,
            );
        }

        if ($this->pluginCatalog === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Plugin catalog is disabled.']],
                404,
            );
        }

        $query = (string) $request->query->get('q', '');
        $packages = $this->pluginCatalog->search($query);
        $info = $this->pluginCatalog->getCacheInfo();

        return new JsonResponse([
            'data' => [
                'packages'    => $packages,
                'cached_at'   => $info['cached_at'],
                'ttl_seconds' => $info['ttl_seconds'],
            ],
        ]);
    }
}
