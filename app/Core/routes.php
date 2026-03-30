<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use Spora\Http\AgentController;
use Spora\Http\AuthController;
use Spora\Http\RecipeController;
use Spora\Http\TaskController;
use Spora\Http\ToolController;

/**
 * Application route definitions.
 * Returns a callable for nikic/fast-route's simpleDispatcher.
 * All routes are prefixed /api/v1 per API_SPEC.md.
 */
return static function (RouteCollector $r): void {
    // Auth
    $r->addRoute('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
    $r->addRoute('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
    $r->addRoute('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
    $r->addRoute('POST', '/api/v1/auth/register', [AuthController::class, 'register']);

    // Agent (V1: single agent per user — no ID in path)
    $r->addRoute('GET', '/api/v1/agent', [AgentController::class, 'show']);
    $r->addRoute('PATCH', '/api/v1/agent', [AgentController::class, 'update']);

    // Agent tools — enablement & auto_approve override
    $r->addRoute('POST', '/api/v1/agent/tools/{toolClass}/enable', [AgentController::class, 'enableTool']);
    $r->addRoute('PATCH', '/api/v1/agent/tools/{toolClass}', [AgentController::class, 'patchTool']);
    $r->addRoute('DELETE', '/api/v1/agent/tools/{toolClass}/enable', [AgentController::class, 'disableTool']);

    // Agent tools — per-agent credential overrides
    $r->addRoute('GET', '/api/v1/agent/tools/{toolClass}/override', [AgentController::class, 'getOverride']);
    $r->addRoute('PUT', '/api/v1/agent/tools/{toolClass}/override', [AgentController::class, 'putOverride']);
    $r->addRoute('DELETE', '/api/v1/agent/tools/{toolClass}/override', [AgentController::class, 'deleteOverride']);

    // Tool registry — global settings
    $r->addRoute('GET', '/api/v1/tools', [ToolController::class, 'index']);
    $r->addRoute('GET', '/api/v1/tools/{toolClass}/settings', [ToolController::class, 'getSettings']);
    $r->addRoute('PUT', '/api/v1/tools/{toolClass}/settings', [ToolController::class, 'putSettings']);

    // Tasks
    $r->addRoute('GET', '/api/v1/tasks', [TaskController::class, 'index']);
    $r->addRoute('POST', '/api/v1/tasks', [TaskController::class, 'store']);
    $r->addRoute('GET', '/api/v1/tasks/{taskId}', [TaskController::class, 'show']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/approve', [TaskController::class, 'approve']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/reject', [TaskController::class, 'reject']);

    // Recipes
    $r->addRoute('GET', '/api/v1/recipes', [RecipeController::class, 'index']);
};
