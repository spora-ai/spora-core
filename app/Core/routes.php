<?php

declare(strict_types=1);

use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\AgentController;
use Spora\Http\AgentMemoryController;
use Spora\Http\AppsController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MemoryController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
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
 * Middleware array as 4th argument: [] = no middleware, [AuthMiddleware, CsrfMiddleware] = protected.
 */
return static function (MiddlewareRouteCollector $r): void {
    // No auth, no CSRF (session not established yet)
    $r->addRoute('GET', '/health', [HealthController::class, 'check'], []);
    // Public config (no auth)
    $r->addRoute('GET', '/api/v1/config', [ConfigController::class, 'index'], []);
    // Apps — protected: app/plugin inventory is only needed inside the authenticated SPA
    $r->addRoute('GET', '/api/v1/apps', [AppsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    // Auth (no auth, CSRF handled per-route)
    $r->addRoute('POST', '/api/v1/auth/login', [AuthController::class, 'login'], []);
    $r->addRoute('POST', '/api/v1/auth/register', [AuthController::class, 'register'], []);
    $r->addRoute('POST', '/api/v1/auth/logout', [AuthController::class, 'logout'], [CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/auth/me', [AuthController::class, 'me'], [CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/auth/password', [AuthController::class, 'password'], [CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/auth/account', [AuthController::class, 'account'], [CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/auth/verify/{selector}', [AuthController::class, 'verify'], []);
    $r->addRoute('POST', '/api/v1/auth/verification/resend', [AuthController::class, 'resendVerification'], []);
    $r->addRoute('POST', '/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword'], []);
    $r->addRoute('POST', '/api/v1/auth/reset-password', [AuthController::class, 'resetPassword'], []);
    $r->addRoute('POST', '/api/v1/auth/email/change-request', [AuthController::class, 'requestEmailChange'], [CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/auth/email/confirm', [AuthController::class, 'confirmEmailChange'], []);

    // Protected routes: auth + CSRF
    $r->addRoute('GET', '/api/v1/agents', [AgentController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/agents', [AgentController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}', [AgentController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/agents/{id}', [AgentController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{id}', [AgentController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Agent tools — enablement
    $r->addRoute('POST', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentController::class, 'enableTool'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentController::class, 'disableTool'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/status', [AgentController::class, 'getToolsStatus'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/status', [AgentController::class, 'getToolStatus'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Agent tools — per-agent credential overrides
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'getOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'putOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/tools/{toolId}/override', [AgentController::class, 'deleteOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Agent tool operations — per-operation enable/auto-approve overrides
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/operations', [AgentController::class, 'getToolsOperations'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentController::class, 'getOperationOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentController::class, 'patchOperationOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Tool registry — global settings
    $r->addRoute('GET', '/api/v1/tools', [ToolController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'getSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'putSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/tools/{toolId}/settings', [ToolController::class, 'deleteSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Tool registry — per-user settings
    $r->addRoute('GET', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'getUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'putUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/tools/{toolId}/user-settings', [ToolController::class, 'deleteUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Tasks
    $r->addRoute('GET', '/api/v1/tasks', [TaskController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/tasks', [TaskController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/tasks/{taskId}', [TaskController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/approve', [TaskController::class, 'approve'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/reject', [TaskController::class, 'reject'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/retry', [TaskController::class, 'retry'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/tasks/{taskId}/continue', [TaskController::class, 'continue'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/tasks/{taskId}/retry-chain', [TaskController::class, 'cancelRetryChain'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/tasks/{taskId}', [TaskController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Recipes
    $r->addRoute('GET', '/api/v1/recipes', [RecipeController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // LLM Driver Configurations — index/show/list need auth; globalConfigs needs admin
    $r->addRoute('GET', '/api/v1/llm-drivers', [LLMConfigController::class, 'drivers'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/llm-configs', [LLMConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/llm-configs', [LLMConfigController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/llm-configs/global', [LLMConfigController::class, 'globalConfigs'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('GET', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/llm-configs/{id}', [LLMConfigController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/llm-configs/{id}/set-default', [LLMConfigController::class, 'setDefault'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // User LLM Preferences
    $r->addRoute('GET', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Global Memories
    $r->addRoute('GET', '/api/v1/memories', [MemoryController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/memories', [MemoryController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/memories/reorder', [MemoryController::class, 'reorder'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/memories/{id}', [MemoryController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/memories/{id}', [MemoryController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/memories/{id}', [MemoryController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Agent Memories
    $r->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Notifications
    $r->addRoute('GET', '/api/v1/notifications', [NotificationController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/notifications', [NotificationController::class, 'destroyAll'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/notifications/{id}', [NotificationController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // User Profile
    $r->addRoute('GET', '/api/v1/me/profile', [UserProfileController::class, 'getProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/me/profile', [UserProfileController::class, 'putProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/me/locations', [UserProfileController::class, 'getLocations'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/me/locations', [UserProfileController::class, 'postLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/me/locations/{id}', [UserProfileController::class, 'putLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/me/locations/{id}', [UserProfileController::class, 'deleteLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Users (admin-only)
    $r->addRoute('GET', '/api/v1/users', [UserController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('POST', '/api/v1/users', [UserController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('GET', '/api/v1/users/{id}', [UserController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/users/{id}', [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('PATCH', '/api/v1/users/{id}', [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/users/{id}', [UserController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('GET', '/api/v1/users/{id}/roles', [UserController::class, 'listRoles'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('POST', '/api/v1/users/{id}/roles', [UserController::class, 'grantRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/users/{id}/roles/{role}', [UserController::class, 'revokeRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

    // Mail Config (admin-only)
    $r->addRoute('GET', '/api/v1/mail-config', [MailConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/mail-config', [MailConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('POST', '/api/v1/mail-config/test', [MailConfigController::class, 'test'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

    // Mail Templates (admin-only)
    $r->addRoute('GET', '/api/v1/mail-templates', [MailTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('POST', '/api/v1/mail-templates', [MailTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('GET', '/api/v1/mail-templates/{name}/preview', [MailTemplateController::class, 'preview'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('GET', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/mail-templates/{id}', [MailTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

    // SSE
    $r->addRoute('GET', '/api/v1/sse/status', [SseController::class, 'status'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/sse/auth', [SseController::class, 'auth'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Prompt Templates
    $r->addRoute('GET', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/templates/{templateId}', [PromptTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

    // Scheduled Runs
    $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('PUT', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('DELETE', '/api/v1/agents/{id}/scheduled-runs/{runId}', [ScheduledRunController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
    $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs/{runId}/trigger', [ScheduledRunController::class, 'trigger'], [AuthMiddleware::class, CsrfMiddleware::class]);
};
