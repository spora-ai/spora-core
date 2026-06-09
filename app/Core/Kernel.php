<?php

declare(strict_types=1);

namespace Spora\Core;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Spora\Http\Exceptions\ForbiddenException;
use Spora\Http\Exceptions\InvalidCsrfTokenException;
use Spora\Http\Exceptions\UnauthenticatedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Kernel
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

    public function __construct()
    {
        $this->loadDotEnv();

        $builder = new ContainerBuilder();
        $builder->addDefinitions($this->loadContainerDefinitions());
        $this->container = $builder->build();

        $this->configureErrorHandling($this->container->get('config')['app_env'] ?? 'production');
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
        return \Spora\Core\ContainerDefinitions::all();
    }

    private function buildRouter(): Router
    {
        return new Router($this->container, [RouteDefinitions::class, 'register']);
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
            default => null,
        };
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
