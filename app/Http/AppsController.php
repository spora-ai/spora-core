<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Apps\AppInterface;
use Spora\Apps\AppRegistry;
use Spora\Apps\VueAppInterface;
use Spora\Plugins\PluginLoader;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Lists all registered applications (plugins) available in the system.
 *
 * Apps that ship a pre-built Vue bundle declare it via
 * {@see VueAppInterface::entry()} (PHP) or `plugin.json`'s
 * `frontendEntry` field (JSON). When either is present, the SPA's
 * generic `/apps/:appName` loader uses it to fetch the IIFE script from
 * `public/plugins/<slug>/<entry>`.
 *
 * The `name` stays stable so existing routes keep working; this is an
 * additive contract change.
 */
final class AppsController
{
    public function __construct(
        private readonly AppRegistry $appRegistry,
        private readonly ?PluginLoader $pluginLoader = null,
    ) {}

    public function index(): JsonResponse
    {
        $apps = $this->appRegistry->all();

        $data = [];
        foreach ($apps as $app) {
            $data[] = $this->serializeApp($app);
        }

        return new JsonResponse(['data' => ['apps' => $data]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApp(AppInterface $app): array
    {
        $entry = $this->resolveFrontendEntry($app);
        $slug = $this->pluginLoader !== null ? $this->pluginLoader->getSlugForApp($app) : null;

        $payload = [
            'name'        => $app->name(),
            'displayName' => $app->displayName(),
            'description' => $app->description(),
            'icon'        => $app->icon(),
            'route'       => '/apps/' . $app->name(),
        ];

        // Only emit `frontendEntry` when there is one — keeps the SPA's
        // loader logic simple ("present → use it, missing → fall back to
        // the hard-coded core routes").
        if ($entry !== null) {
            $payload['frontendEntry'] = $entry;
        }

        // `slug` is the on-disk bundle directory, distinct from `name` (the
        // route key). Core-owned apps ship no bundle, so the key is
        // deliberately omitted rather than emitted as null.
        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        return $payload;
    }

    /**
     * Prefer the PHP-declared entry from {@see VueAppInterface}; fall back
     * to `plugin.json`'s `frontendEntry` so JSON-only plugins can still
     * ship a UI without writing PHP.
     */
    private function resolveFrontendEntry(AppInterface $app): ?string
    {
        if ($app instanceof VueAppInterface) {
            $value = $app->entry();
            return $value !== '' ? $value : null;
        }

        $slug = $this->pluginLoader?->getSlugForApp($app);
        if ($slug !== null) {
            $manifest = $this->pluginLoader->getPluginManifest($slug);
            if (is_array($manifest)) {
                $value = $manifest['frontendEntry'] ?? null;
                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
