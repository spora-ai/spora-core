<?php

declare(strict_types=1);

use FastRoute\RouteCollector;
use Spora\Http\AgentController;
use Spora\Http\AgentMemoryController;
use Spora\Http\AppsController;
use Spora\Http\AuthController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MemoryController;
use Spora\Http\NotificationController;
use Spora\Http\PromptTemplateController;
use Spora\Http\RecipeController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;

/**
 * Application route definitions.
 * Returns a callable for nikic/fast-route's simpleDispatcher.
 * All routes are prefixed /api/v1 per API_SPEC.md.
 */
return static function (RouteCollector $r): void {
    // Health check (no auth)
    $r->addRoute('GET', '/health', [HealthController::class, 'check']);
    // Apps
    $r->addRoute('GET', '/api/v1/apps', [AppsController::class, 'index']);
    // Auth
    $r->addRoute('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
    $r->addRoute('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
    $r->addRoute('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
    $r->addRoute('POST', '/api/v1/auth/register', [AuthController::class, 'register']);
    $r->addRoute('PATCH', '/api/v1/auth/password', [AuthController::class, 'password']);
    $r->addRoute('PATCH', '/api/v1/auth/account', [AuthController::class, 'account']);

    // Agents — CRUD
    $r->addRoute('GET', '/api/v1/agents', [AgentController::class, 'index']);
    $r->addRoute('POST', '/api/v1/agents', [AgentController::class, 'store']);
    $r->addRoute('GET', '/api/v1/agents/{id}', [AgentController::class, 'show']);
    $r->addRoute('PATCH', '/api/v1/agents/{id}', [AgentController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/agents/{id}', [AgentController::class, 'destroy']);

    // Agent tools — enablement & auto_approve override
    $r->addRoute('POST', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentController::class, 'enableTool']);
    $r->addRoute('PATCH', '/api/v1/agents/{id}/tools/{toolId}', [AgentController::class, 'patchTool']);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentController::class, 'disableTool']);
    // Batch status endpoint MUST be registered before per-tool status to avoid FastRoute overlap
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/status', [AgentController::class, 'getToolsStatus']);
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/status', [AgentController::class, 'getToolStatus']);

    // Agent tools — per-agent credential overrides
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'getOverride']);
    $r->addRoute('PUT', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'putOverride']);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'deleteOverride']);

    // Agent tool operations — per-operation enable/auto-approve overrides
    // Batch endpoint MUST be before per-operation route (2 static segments vs 3)
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/operations', [AgentController::class, 'getToolsOperations']);
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentController::class, 'getOperationOverride']);
    $r->addRoute('PATCH', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentController::class, 'patchOperationOverride']);

    // Tool registry — global settings
    $r->addRoute('GET', '/api/v1/tools', [ToolController::class, 'index']);
    $r->addRoute('GET', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'getSettings']);
    $r->addRoute('PUT', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'putSettings']);
    $r->addRoute('DELETE', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'deleteSettings']);

    // Tool registry — per-user settings
    $r->addRoute('GET', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'getUserSettings']);
    $r->addRoute('PUT', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'putUserSettings']);
    $r->addRoute('DELETE', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'deleteUserSettings']);

    // Tasks
    $r->addRoute('GET', '/api/v1/tasks', [TaskController::class, 'index']);
    $r->addRoute('POST', '/api/v1/tasks', [TaskController::class, 'store']);
    $r->addRoute('GET', '/api/v1/tasks/{taskId}', [TaskController::class, 'show']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/approve', [TaskController::class, 'approve']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/reject', [TaskController::class, 'reject']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/retry', [TaskController::class, 'retry']);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/continue', [TaskController::class, 'continue']);
    $r->addRoute('DELETE', '/api/v1/tasks/{taskId}/retry-chain', [TaskController::class, 'cancelRetryChain']);
    $r->addRoute('DELETE', '/api/v1/tasks/{taskId}', [TaskController::class, 'destroy']);

    // Recipes
    $r->addRoute('GET', '/api/v1/recipes', [RecipeController::class, 'index']);

    // LLM Driver Configurations
    $r->addRoute('GET', '/api/v1/llm-drivers', [LLMConfigController::class, 'drivers']);
    $r->addRoute('GET', '/api/v1/llm-configs/global', [LLMConfigController::class, 'globalConfigs']);
    $r->addRoute('GET', '/api/v1/llm-configs', [LLMConfigController::class, 'index']);
    $r->addRoute('POST', '/api/v1/llm-configs', [LLMConfigController::class, 'store']);
    $r->addRoute('GET', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'destroy']);
    $r->addRoute('POST', '/api/v1/llm-configs/{id}/set-default', [LLMConfigController::class, 'setDefault']);

    // User LLM Preferences
    $r->addRoute('GET', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'update']);

    // Global Memories
    $r->addRoute('GET', '/api/v1/memories', [MemoryController::class, 'index']);
    $r->addRoute('POST', '/api/v1/memories', [MemoryController::class, 'store']);
    $r->addRoute('PATCH', '/api/v1/memories/reorder', [MemoryController::class, 'reorder']);
    $r->addRoute('GET', '/api/v1/memories/{id}', [MemoryController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/memories/{id}', [MemoryController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/memories/{id}', [MemoryController::class, 'destroy']);

    // Agent Memories
    $r->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index']);
    $r->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store']);
    $r->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder']);
    $r->addRoute('GET', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'destroy']);

    // Notifications
    $r->addRoute('GET', '/api/v1/notifications', [NotificationController::class, 'index']);
    $r->addRoute('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead']);
    $r->addRoute('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead']);
    $r->addRoute('DELETE', '/api/v1/notifications', [NotificationController::class, 'destroyAll']);
    $r->addRoute('DELETE', '/api/v1/notifications/{id}', [NotificationController::class, 'destroy']);

    // User Profile
    $r->addRoute('GET', '/api/v1/me/profile', [UserProfileController::class, 'getProfile']);
    $r->addRoute('PUT', '/api/v1/me/profile', [UserProfileController::class, 'putProfile']);
    $r->addRoute('GET', '/api/v1/me/locations', [UserProfileController::class, 'getLocations']);
    $r->addRoute('POST', '/api/v1/me/locations', [UserProfileController::class, 'postLocation']);
    $r->addRoute('PUT', '/api/v1/me/locations/{id}', [UserProfileController::class, 'putLocation']);
    $r->addRoute('DELETE', '/api/v1/me/locations/{id}', [UserProfileController::class, 'deleteLocation']);

    // Users (admin-only)
    $r->addRoute('GET', '/api/v1/users', [UserController::class, 'index']);
    $r->addRoute('POST', '/api/v1/users', [UserController::class, 'store']);
    $r->addRoute('GET', '/api/v1/users/{id}', [UserController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/users/{id}', [UserController::class, 'update']);
    $r->addRoute('PATCH', '/api/v1/users/{id}', [UserController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/users/{id}', [UserController::class, 'destroy']);
    $r->addRoute('GET', '/api/v1/users/{id}/roles', [UserController::class, 'listRoles']);
    $r->addRoute('POST', '/api/v1/users/{id}/roles', [UserController::class, 'grantRole']);
    $r->addRoute('DELETE', '/api/v1/users/{id}/roles/{role}', [UserController::class, 'revokeRole']);

    // Mail Config (admin-only)
    $r->addRoute('GET', '/api/v1/mail-config', [MailConfigController::class, 'index']);
    $r->addRoute('PUT', '/api/v1/mail-config', [MailConfigController::class, 'update']);
    $r->addRoute('POST', '/api/v1/mail-config/test', [MailConfigController::class, 'test']);

    // Mail Templates (admin-only)
    $r->addRoute('GET', '/api/v1/mail-templates', [MailTemplateController::class, 'index']);
    $r->addRoute('POST', '/api/v1/mail-templates', [MailTemplateController::class, 'store']);
    $r->addRoute('GET', '/api/v1/mail-templates/{name}/preview', [MailTemplateController::class, 'preview']);
    $r->addRoute('GET', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'destroy']);

    // SSE
    $r->addRoute('GET', '/api/v1/sse/status', [SseController::class, 'status']);
    $r->addRoute('GET', '/api/v1/sse/auth', [SseController::class, 'auth']);

    // Prompt Templates
    $r->addRoute('GET', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'index']);
    $r->addRoute('POST', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'store']);
    $r->addRoute('GET', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'destroy']);

    // Scheduled Runs
    $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'index']);
    $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'store']);
    $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'show']);
    $r->addRoute('PUT', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'destroy']);
    $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs/{runId}/trigger', [ScheduledRunController::class, 'trigger']);
};
