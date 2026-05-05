<?php

declare(strict_types=1);

namespace Spora\Core;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use FastRoute\RouteCollector;
use Psr\Log\LoggerInterface;
use Spora\Http\Exceptions\ForbiddenException;
use Spora\Http\Exceptions\UnauthenticatedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Kernel
{
    private Container $container;

    public function __construct()
    {
        $this->loadDotEnv();

        $builder = new ContainerBuilder();
        $builder->addDefinitions($this->loadContainerDefinitions());
        $this->container = $builder->build();

        $this->configureErrorHandling($this->container->get('config')['app_env'] ?? 'production');
    }

    public function handle(Request $request): Response
    {
        try {
            $this->container->get(Database::class)->boot();
            $router = $this->buildRouter();
            return $router->dispatch($request);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    private function loadDotEnv(): void
    {
        $envFile = BASE_PATH . '/.env';

        if (!file_exists($envFile)) {
            return;
        }

        $dotenv = Dotenv::createImmutable(BASE_PATH);
        $dotenv->safeLoad();
    }

    private function loadContainerDefinitions(): array
    {
        $containerFile = BASE_PATH . '/app/Core/container.php';

        if (!file_exists($containerFile)) {
            return [];
        }

        return require $containerFile;
    }

    private function buildRouter(): Router
    {
        $routeFile = BASE_PATH . '/app/Core/routes.php';

        $routeDefinitions = file_exists($routeFile)
            ? require $routeFile
            : static function (RouteCollector $r): void {};

        return new Router($this->container, $routeDefinitions);
    }

    private function handleException(Throwable $e): Response
    {
        if ($e instanceof UnauthenticatedException) {
            return new JsonResponse(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($e instanceof ForbiddenException) {
            return new JsonResponse(
                ['error' => ['code' => 'FORBIDDEN', 'message' => $e->getMessage()]],
                Response::HTTP_FORBIDDEN,
            );
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

    private function configureErrorHandling(string $appEnv): void
    {
        // In production, suppress deprecations from output but log everything.
        // In development, also log everything (don't display raw errors to JSON API).
        if ($appEnv === 'production') {
            error_reporting(E_ALL & ~E_DEPRECATED);
        } else {
            error_reporting(E_ALL);
        }
        ini_set('display_errors', '0');

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($appEnv): bool {
            $logLevel = match ($errno) {
                E_DEPRECATED, E_USER_DEPRECATED => 'warning',
                E_WARNING, E_USER_WARNING => 'warning',
                E_NOTICE, E_USER_NOTICE => 'info',
                E_STRICT => 'info',
                default => 'error',
            };
            $this->container->get(LoggerInterface::class)->$logLevel(
                sprintf('PHP error %d: %s in %s:%d', $errno, $errstr, $errfile, $errline),
            );

            return true;
        });
    }
}
