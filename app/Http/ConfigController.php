<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns public application configuration for the frontend.
 */
final class ConfigController
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'allow_registration' => (bool) ($this->config['allow_registration'] ?? true),
        ]);
    }
}
