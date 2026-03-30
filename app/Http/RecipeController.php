<?php

declare(strict_types=1);

namespace Spora\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles recipe listing.
 * TODO: implement when recipe file scanning is built.
 */
final class RecipeController
{
    public function index(Request $request, array $vars = []): Response
    {
        throw new RuntimeException('RecipeController::index() not implemented.');
    }
}
