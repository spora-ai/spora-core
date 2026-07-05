<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use LogicException;
use Spora\Core\Extension\PluginInstallRequest;
use Spora\Core\Extension\PluginManager;
use Spora\Http\Exceptions\FeatureDisabledException;
use Spora\Http\Exceptions\PluginCatalogNotWiredException;
use Spora\Services\PluginCatalogService;
use Spora\Services\PluginsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plugin inventory + Web UI install surface.
 *
 * - GET    /api/v1/plugins             — list installed plugins (any authenticated user)
 * - GET    /api/v1/plugins/catalog?q=  — browse the Packagist catalog (any authenticated user; 404 when disabled)
 * - POST   /api/v1/plugins             — install from registry or local path (admin)
 * - DELETE /api/v1/plugins/{package}   — uninstall (admin)
 * - PATCH  /api/v1/plugins/{package}   — update (admin)
 *
 * The three mutating routes honour `SPORA_PLUGIN_INSTALL_ENABLED`
 * (default false). When off, they throw `FeatureDisabledException`,
 * which the Kernel maps to `403 FEATURE_DISABLED`. The CLI
 * `php bin/spora plugin:install|uninstall|update` is not gated — it's
 * always available as the operator recovery path.
 *
 * Wire contract is locked in docs/20_plugin_install_api.md.
 */
final class PluginsController
{
    /** Composer vendor/name — same shape as Packagist enforces. */
    private const PACKAGE_REGEX = '/^[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?\/[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?$/';

    public function __construct(
        private readonly PluginsService $pluginsService,
        private readonly ?PluginManager $pluginManager,
        private readonly bool $pluginInstallEnabled,
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
     * Returns 404 when `SPORA_PLUGIN_CATALOG_ENABLED=false` so the navbar
     * item hides cleanly without a per-render feature check.
     */
    public function catalog(Request $request): JsonResponse
    {
        if (!$this->pluginCatalogEnabled) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Plugin catalog is disabled.']],
                404,
            );
        }

        // Wiring error: catalog is enabled but the service was never registered.
        // Surface a 500 so misconfiguration is loud during ops, not silent as 404.
        if ($this->pluginCatalog === null) {
            throw new PluginCatalogNotWiredException(
                'Plugin catalog is enabled but the PluginCatalogService is not wired in the DI container.',
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

    /**
     * POST /api/v1/plugins
     *
     * Body: {"package": "vendor/name", "constraint": "^0.2", "path": "..."}.
     * `constraint` and `path` are mutually exclusive.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireInstallEnabled();

        $body = $this->decodeBody($request);
        $validated = $this->validateInstallRequest($body);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$package, $constraint, $path] = $validated;

        return $this->runInstall($package, $constraint, $path);
    }

    /**
     * Validate `store()`'s decoded body. Returns the (package, constraint,
     * path) triple on success, or the first failing 400 JsonResponse.
     * Extracted to keep `store()` under the 3-return Sonar cap (S1142).
     *
     * @param array<string, mixed>|null $body
     * @return array{0: string, 1: ?string, 2: ?string}|JsonResponse
     */
    private function validateInstallRequest(?array $body): array|JsonResponse
    {
        $package = is_array($body) ? $this->asString($body, 'package') : null;
        $constraint = is_array($body) ? $this->asString($body, 'constraint') : null;
        $path = is_array($body) ? $this->asString($body, 'path') : null;

        $failure = $this->validateInstallBodyShape($body)
            ?? $this->validateInstallPackage($package)
            ?? $this->validateInstallMode($constraint, $path);
        if ($failure !== null) {
            return $failure;
        }

        return [$package, $constraint, $path];
    }

    private function validateInstallBodyShape(?array $body): ?JsonResponse
    {
        if (!is_array($body)) {
            return $this->error('VALIDATION_FAILED', 'Request body must be a JSON object.', Response::HTTP_BAD_REQUEST);
        }
        return null;
    }

    private function validateInstallPackage(?string $package): ?JsonResponse
    {
        if ($package === null) {
            return $this->error('VALIDATION_FAILED', 'Missing required field: package.', Response::HTTP_BAD_REQUEST);
        }
        if (preg_match(self::PACKAGE_REGEX, $package) !== 1) {
            return $this->error(
                'VALIDATION_FAILED',
                'Field `package` must be a Composer vendor/name (e.g. "spora-ai/spora-plugin-tavily").',
                Response::HTTP_BAD_REQUEST,
            );
        }
        return null;
    }

    private function validateInstallMode(?string $constraint, ?string $path): ?JsonResponse
    {
        if ($constraint !== null && $path !== null) {
            return $this->error(
                'VALIDATION_FAILED',
                'Pass either `constraint` (registry) or `path` (local), not both.',
                Response::HTTP_BAD_REQUEST,
            );
        }
        if ($path !== null && !str_starts_with($path, '/')) {
            return $this->error(
                'VALIDATION_FAILED',
                'Field `path` must be an absolute filesystem path (start with `/`).',
                Response::HTTP_BAD_REQUEST,
            );
        }
        return null;
    }

    private function runInstall(string $package, ?string $constraint, ?string $path): JsonResponse
    {
        $manager = $this->requirePluginManager();
        $result  = $manager->install(new PluginInstallRequest($package, $constraint, $path));

        return new JsonResponse(['data' => $this->resultToArray($result)]);
    }

    /**
     * DELETE /api/v1/plugins/{package}
     */
    public function destroy(string $package): JsonResponse
    {
        $this->requireInstallEnabled();

        if (preg_match(self::PACKAGE_REGEX, $package) !== 1) {
            return $this->error(
                'VALIDATION_FAILED',
                'Path segment `package` must be a Composer vendor/name.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->requirePluginManager()->uninstall($package);

        return new JsonResponse(['data' => $this->resultToArray($result)]);
    }

    /**
     * PATCH /api/v1/plugins/{package}
     *
     * Body (optional): {"constraint": "^0.3"} — re-pin before update.
     */
    public function update(string $package, Request $request): JsonResponse
    {
        $this->requireInstallEnabled();

        if (preg_match(self::PACKAGE_REGEX, $package) !== 1) {
            return $this->error(
                'VALIDATION_FAILED',
                'Path segment `package` must be a Composer vendor/name.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $body = $this->decodeBody($request);
        if ($body === null) {
            return $this->error('VALIDATION_FAILED', 'Request body must be a JSON object.', Response::HTTP_BAD_REQUEST);
        }
        $constraint = $this->asString($body, 'constraint');

        // A re-pin re-uses install() (composer require <pkg>:<c> both upgrades
        // and pins) — matching the CLI `plugin:update --constraint=` path.
        $manager = $this->requirePluginManager();
        $result  = $constraint !== null
            ? $manager->install(new PluginInstallRequest($package, $constraint))
            : $manager->update($package);

        return new JsonResponse(['data' => $this->resultToArray($result)]);
    }

    /**
     * Gate for the three mutating routes. The Kernel maps the thrown
     * {@see FeatureDisabledException} to a 403 FEATURE_DISABLED envelope.
     */
    private function requireInstallEnabled(): void
    {
        if (!$this->pluginInstallEnabled) {
            throw new FeatureDisabledException(
                'Plugin install via the Web UI is disabled. Set SPORA_PLUGIN_INSTALL_ENABLED=true to enable.',
            );
        }
    }

    /**
     * Production wires a real PluginManager via DI. Read-only `index()` tests
     * pass null; this guards the mutating routes with a loud failure.
     */
    private function requirePluginManager(): PluginManager
    {
        if ($this->pluginManager === null) {
            throw new LogicException(
                'PluginManager is not wired for this controller. '
                . 'Production wires it via ContainerDefinitions; tests should use the install-enabled factory.',
            );
        }
        return $this->pluginManager;
    }

    /**
     * @return array<string, mixed>|null  Decoded body. Empty body → [] (no
     * body sent). Malformed JSON or a non-object value (string, number,
     * list) → null (caller must reject as 400).
     */
    private function decodeBody(Request $request): ?array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        return $this->decodeJsonObject($content);
    }

    /**
     * Decode a non-empty request body into a JSON object. Malformed JSON, or
     * a top-level value that isn't an object (lists, scalars), returns null.
     * Extracted to keep `decodeBody()` under the 3-return Sonar cap (S1142).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        // json_decode(..., true) returns arrays for both JSON objects and
        // JSON lists; treat sequential arrays as a different shape and reject.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function asString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $value = $body[$key];
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultToArray(\Spora\Core\Extension\PluginInstallResult $result): array
    {
        return [
            'package'    => $result->package,
            'status'     => $result->status,
            'constraint' => $result->constraint,
            'path'       => $result->path,
            'message'    => $result->message,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}