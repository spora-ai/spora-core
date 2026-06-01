<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Apps\AppRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Lists all registered applications (plugins) available in the system.
 */
final class AppsController
{
    public function __construct(
        private readonly AppRegistry $appRegistry,
    ) {}

    public function index(): JsonResponse
    {
        $apps = $this->appRegistry->all();

        $data = [];
        foreach ($apps as $app) {
            $data[] = [
                'name' => $app->name(),
                'displayName' => $app->displayName(),
                'description' => $app->description(),
                'icon' => $app->icon(),
                'route' => '/apps/' . $app->name(),
            ];
        }

        return new JsonResponse(['data' => ['apps' => $data]]);
    }
}
