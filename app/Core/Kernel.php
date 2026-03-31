<?php

declare(strict_types=1);

namespace Spora\Core;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use FastRoute\RouteCollector;
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

        // Always log to stderr — never expose file paths in production responses
        error_log(sprintf(
            '[Spora] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        return new JsonResponse($body, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
