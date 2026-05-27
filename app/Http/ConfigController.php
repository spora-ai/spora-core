<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ConfigController
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function index(Request $request, array $vars = []): JsonResponse
    {
        return new JsonResponse([
            'allow_registration' => (bool) ($this->config['allow_registration'] ?? true),
        ]);
    }
}
