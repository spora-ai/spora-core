<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Recipes\RecipeScanner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists all available recipe definitions scanned from the recipes/ directory.
 */
final class RecipeController
{
    public function __construct(
        private readonly AuthService   $auth,
        private readonly RecipeScanner $scanner,
    ) {}

    public function index(Request $_request, array $_vars = []): Response
    {
        if ($this->auth->currentUserId() === null) {
            throw new UnauthenticatedException();
        }

        return new JsonResponse(['data' => ['recipes' => $this->scanner->scan()]]);
    }
}
