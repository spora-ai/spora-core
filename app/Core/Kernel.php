<?php

declare(strict_types=1);

namespace Spora\Core;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Spora\Core\Exceptions\BasePathNotDefinedException;
use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Extensions\AppLoader;
use Spora\Http\Exceptions\FeatureDisabledException;
use Spora\Http\Exceptions\ForbiddenException;
use Spora\Http\Exceptions\InvalidCsrfTokenException;
use Spora\Http\Exceptions\PluginCatalogNotWiredException;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Plugins\PluginLoader;
use Spora\Services\Exceptions\CatalogUnavailableException;
use Spora\Services\Exceptions\MalformedCatalogException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Kernel implements KernelInterface
{
    /**
     * The application kernel that bootstraps the entire Spora framework.
     *
     * Handles environment loading, dependency injection container setup,
     * HTTP request routing, error handling, and request/response lifecycle.
     * This is the entry point for all HTTP requests to the application.
     */
    private Container $container;
    private bool $errorHandlerInstalled = false;
    private ?string $pluginBootError = null;

    private readonly Paths $paths;
    private readonly AppLoader $appLoader;
    private readonly PluginLoader $pluginLoader;

    public function __construct(?Paths $paths = null)
    {
        $this->paths = $paths ?? new Paths(self::resolveBasePath());
        $this->loadDotEnv();

        $builder = new ContainerBuilder();
        $builder->addDefinitions($this->loadContainerDefinitions());

        // AppLoader is owned by the Kernel (not lazily constructed inside a factory)
        // because its `load()` step applies App::register() to the ContainerBuilder
        // BEFORE the container is built — DI bindings must be in place first.
        $this->appLoader = new AppLoader();
        $this->appLoader->load($this->paths, $builder);

        // Register the live AppLoader instance so container-managed services
        // (e.g. Database → DatabaseSchemaInstaller) can resolve it later.
        $builder->addDefinitions([AppLoader::class => $this->appLoader]);

        // Eager-construct the PluginLoader (same pattern as AppLoader above) so
        // its `register()` hook can add DI bindings to the ContainerBuilder BEFORE
        // it is built. The previous lazy factory inside ContainerDefinitions
        // closed the window for any plugin-supplied bindings.
        $this->pluginLoader = new PluginLoader(
            [$this->paths->plugins()],
            $this->paths->storage('.plugins_stamp'),
        );
        // A bad manifest should not crash boot — fall through with an empty
        // loader. Mirrors registerPlugins()' per-plugin tolerance below.
        try {
            $this->pluginLoader->boot();
        } catch (\Spora\Plugins\Exceptions\PluginLoadFailedException) {
            $this->pluginBootError = 'Plugin manifest in ' . $this->paths->plugins()
                . ' is not loadable; plugin boot was skipped.';
        }
        $this->pluginLoader->registerPlugins($builder);
        $builder->addDefinitions([PluginLoader::class => $this->pluginLoader]);

        $this->container = $builder->build();

        $this->configureErrorHandling($this->container->get('config')['app_env'] ?? 'production');

        if ($this->pluginBootError !== null) {
            $this->container->get(LoggerInterface::class)->warning($this->pluginBootError);
            $this->pluginBootError = null;
        }
    }

    public function __destruct()
    {
        if ($this->errorHandlerInstalled) {
            restore_error_handler();
            $this->errorHandlerInstalled = false;
        }
    }

    public function handle(Request $request): Response
    {
        try {
            $this->container->get(Database::class)->boot();
            $router = $this->buildRouter();
            // App::boot() runs once per request, after the container is built
            // and the DB is up — services are safe to use here.
            $this->appLoader->boot();
            // Plugin::boot() runs after App::boot() so plugin authors can use
            // any service the App registered. Idempotent within a process.
            $this->pluginLoader->bootExtensions();
            return $router->dispatch($request);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getAppLoader(): AppLoader
    {
        return $this->appLoader;
    }

    /**
     * Resolve BASE_PATH from the constant when defined, or throw a dedicated
     * exception so callers see a clear, actionable message rather than an
     * "undefined constant" fatal.
     */
    private static function resolveBasePath(): string
    {
        if (!defined('BASE_PATH')) {
            throw new BasePathNotDefinedException(
                'BASE_PATH is not defined. Add `define(\'BASE_PATH\', dirname(__FILE__, 2));` '
                . 'to your public/index.php (web entry) and bin/spora (CLI entry) '
                . 'before any Spora framework code runs.',
            );
        }
        return BASE_PATH;
    }

    private function loadDotEnv(): void
    {
        $envFile = $this->paths->env();

        if (!file_exists($envFile)) {
            return;
        }

        $dotenv = Dotenv::createImmutable(dirname($envFile));
        $dotenv->safeLoad();
    }

    private function loadContainerDefinitions(): array
    {
        return ContainerDefinitions::all();
    }

    private function buildRouter(): Router
    {
        return new Router(
            $this->container,
            function (MiddlewareRouteCollector $r): void {
                RouteDefinitions::register($r);
                $this->appLoader->registerRoutes($r);
                // Plugin::routes() runs after App::routes() so plugin authors
                // can override or extend App-registered routes.
                $this->pluginLoader->registerRoutes($r);
            },
        );
    }

    private function handleException(Throwable $e): Response
    {
        $response = $this->mapKnownExceptionToResponse($e);
        if ($response !== null) {
            return $response;
        }

        $config  = $this->container->has('config') ? $this->container->get('config') : [];
        $appEnv  = $config['app_env'] ?? 'production';
        $isDebug = $appEnv === 'development' || $appEnv === 'local';

        $body = ['error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'An unexpected error occurred.']];

        if ($isDebug) {
            $body['debug'] = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        // Log to stderr — include file/line only in debug mode to avoid leaking paths in production
        if ($isDebug) {
            error_log(sprintf(
                '[Spora] %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        } else {
            error_log(sprintf(
                '[Spora] %s: %s',
                get_class($e),
                $e->getMessage(),
            ));
        }

        return new JsonResponse($body, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function mapKnownExceptionToResponse(Throwable $e): ?Response
    {
        return match (true) {
            $e instanceof UnauthenticatedException => new JsonResponse(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
                Response::HTTP_UNAUTHORIZED,
            ),
            $e instanceof ForbiddenException => new JsonResponse(
                ['error' => ['code' => 'FORBIDDEN', 'message' => $e->getMessage()]],
                Response::HTTP_FORBIDDEN,
            ),
            $e instanceof InvalidCsrfTokenException => new JsonResponse(
                ['error' => ['code' => 'CSRF_INVALID', 'message' => $e->getMessage()]],
                Response::HTTP_FORBIDDEN,
            ),
            $e instanceof CatalogUnavailableException => new JsonResponse(
                ['error' => ['code' => 'CATALOG_UNAVAILABLE', 'message' => $e->getMessage()]],
                Response::HTTP_SERVICE_UNAVAILABLE,
            ),
            $e instanceof MalformedCatalogException => new JsonResponse(
                ['error' => ['code' => 'MALFORMED_CATALOG', 'message' => $e->getMessage()]],
                Response::HTTP_BAD_GATEWAY,
            ),
            $e instanceof PluginCatalogNotWiredException => new JsonResponse(
                ['error' => ['code' => 'PLUGIN_CATALOG_NOT_WIRED', 'message' => $e->getMessage()]],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            ),
            $e instanceof FeatureDisabledException => new JsonResponse(
                ['error' => ['code' => 'FEATURE_DISABLED', 'message' => $e->getMessage()]],
                Response::HTTP_FORBIDDEN,
            ),
            $e instanceof PluginInstallFailedException => self::mapPluginInstallFailureToResponse($e),
            default => null,
        };
    }

    /**
     * Map {@see PluginInstallFailedException} to the JSON envelope documented
     * in docs/20_plugin_install_api.md § "Composer-failure body (500)".
     *
     * Stderr is truncated to a hard 8 KiB ceiling (suffix included) so a
     * runaway Composer error doesn't blow up the response. The full output
     * is in storage/spora.log.
     *
     * `public static` so unit tests verify the mapping without booting a
     * full Kernel.
     */
    public static function mapPluginInstallFailureToResponse(PluginInstallFailedException $e): JsonResponse
    {
        $stderr = $e->stderr;
        $suffix = '… [truncated; see storage/spora.log]';
        $budget = 8192;
        if (strlen($stderr) > $budget - strlen($suffix)) {
            $stderr = substr($stderr, 0, $budget - strlen($suffix)) . $suffix;
        }

        return new JsonResponse(
            [
                'error'   => [
                    'code'    => 'PLUGIN_INSTALL_FAILED',
                    'message' => sprintf(
                        'composer exited with code %d. See `details.stderr` or storage/spora.log for the full output.',
                        $e->exitCode,
                    ),
                ],
                'details' => [
                    'exit_code' => $e->exitCode,
                    'stderr'    => $stderr,
                ],
            ],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function configureErrorHandling(string $appEnv): void
    {
        // In testing mode, don't install a custom error handler — Pest PHPUnit already has one.
        if ($appEnv === 'testing') {
            return;
        }

        if ($appEnv === 'production') {
            error_reporting(E_ALL & ~E_DEPRECATED);
        } else {
            error_reporting(E_ALL);
        }
        ini_set('display_errors', '0');

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) {
                return true;
            }

            $logLevel = match ($errno) {
                E_DEPRECATED, E_USER_DEPRECATED => 'warning',
                E_WARNING, E_USER_WARNING => 'warning',
                E_NOTICE, E_USER_NOTICE => 'info',
                default => 'error',
            };
            $this->container->get(LoggerInterface::class)->$logLevel(
                sprintf('PHP error %d: %s in %s:%d', $errno, $errstr, $errfile, $errline),
            );

            return true;
        });
        $this->errorHandlerInstalled = true;
    }
}
