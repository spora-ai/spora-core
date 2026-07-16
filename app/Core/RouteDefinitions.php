<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\AgentController;
use Spora\Http\AgentMemoryController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentTemplateController;
use Spora\Http\AgentToolController;
use Spora\Http\AppsController;
use Spora\Http\AssetController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MediaAllowedTypesController;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaUploadController;
use Spora\Http\MemoryController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Http\PluginsController;
use Spora\Http\PromptTemplateController;
use Spora\Http\PublicMediaController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;

final class RouteDefinitions
{
    public const ROUTE_MEDIA_ITEM = '/api/v1/media/{id}';

    public const ROUTE_AGENTS_ID = '/api/v1/agents/{id}';
    public const ROUTE_AGENTS_TOOL_OVERRIDE = '/api/v1/agents/{id}/tools/{toolId}/override';
    public const ROUTE_TOOLS_SETTINGS = '/api/v1/tools/{toolId}/settings';
    public const ROUTE_TOOLS_USER_SETTINGS = '/api/v1/tools/{toolId}/user-settings';
    public const ROUTE_LLM_CONFIGS_ID = '/api/v1/llm-configs/{id}';
    public const ROUTE_MEMORIES_ID = '/api/v1/memories/{id}';
    public const ROUTE_AGENTS_MEMORIES_MEMORY_ID = '/api/v1/agents/{agentId}/memories/{memoryId}';
    public const ROUTE_USERS_ID = '/api/v1/users/{id}';
    public const ROUTE_MAIL_TEMPLATES_ID = '/api/v1/mail-templates/{id}';
    public const ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID = '/api/v1/agents/{id}/templates/{templateId}';
    public const ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID = '/api/v1/agents/{id}/scheduled-runs/{runId}';

    public static function register(MiddlewareRouteCollector $r): void
    {
        $r->addRoute('GET', '/api/health', [HealthController::class, 'check'], []);
        $r->addRoute('GET', '/api/v1/config', [ConfigController::class, 'index'], []);

        // Asset serving — authenticated; the controller enforces ownership
        // (asset.task.user_id == currentUserId, with admin bypass). The
        // URL is no longer the authorization token because the new
        // opaque-URL form (`/api/v1/assets/<uuid>`) uses the row's
        // primary key as the URL component, so anyone with the URL
        // could otherwise fetch the bytes.
        $r->addRoute('GET', '/api/v1/assets/{filename}', [AssetController::class, 'show'], [AuthMiddleware::class]);
        $r->addRoute('GET', '/api/v1/apps', [AppsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/plugins', [PluginsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        // Plugin catalog (Packagist browse) — Auth only. Read-only, so no Csrf.
        // Admin not required: any logged-in user can browse. The controller
        // returns 404 when SPORA_PLUGIN_CATALOG_ENABLED=false so the navbar
        // item can hide cleanly.
        $r->addRoute('GET', '/api/v1/plugins/catalog', [PluginsController::class, 'catalog'], [AuthMiddleware::class]);
        $r->addRoute('POST', '/api/v1/plugins', [PluginsController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/plugins/{package}', [PluginsController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/plugins/{package}', [PluginsController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
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

        $r->addRoute('GET', '/api/v1/agents', [AgentController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents', [AgentController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_ID, [AgentController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_AGENTS_ID, [AgentController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_ID, [AgentController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('POST', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentToolController::class, 'enableTool'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/agents/{id}/tools/{toolId}/enable', [AgentToolController::class, 'disableTool'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agents/{id}/tools/status', [AgentToolController::class, 'getToolsStatus'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/status', [AgentToolController::class, 'getToolStatus'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_AGENTS_TOOL_OVERRIDE, [AgentOverrideController::class, 'getOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_TOOL_OVERRIDE, [AgentOverrideController::class, 'putOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_TOOL_OVERRIDE, [AgentOverrideController::class, 'deleteOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/agents/{id}/tools/operations', [AgentToolController::class, 'getToolsOperations'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentOverrideController::class, 'getOperationOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/agents/{id}/tools/{toolId}/operations/{operation}', [AgentOverrideController::class, 'patchOperationOverride'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/tools', [ToolController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'getSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'putSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'deleteSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'getUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'putUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'deleteUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/tasks', [TaskController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks', [TaskController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/tasks/{taskId}', [TaskController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/approve', [TaskController::class, 'approve'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/reject', [TaskController::class, 'reject'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/retry', [TaskController::class, 'retry'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/continue', [TaskController::class, 'continue'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/tasks/{taskId}/retry-chain', [TaskController::class, 'cancelRetryChain'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/tasks/{taskId}', [TaskController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Media Archive — read & delete surface for the operator. Plugin
        // tools write rows via MediaArchiveService::ingest(); this route
        // set is for browsing and cleanup.
        $r->addRoute('GET', '/api/v1/media', [MediaArchiveController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/media/allowed-types', [MediaAllowedTypesController::class, 'index'], [AuthMiddleware::class]);
        $r->addRoute('POST', '/api/v1/media', [MediaUploadController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_MEDIA_ITEM, [MediaArchiveController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_MEDIA_ITEM, [MediaArchiveController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_MEDIA_ITEM . '/public-token/refresh', [MediaArchiveController::class, 'refreshPublicToken'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_MEDIA_ITEM, [MediaArchiveController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Public, token-gated media access. No auth middleware — the token
        // itself is the credential. The id is always a UUID shape; the
        // controller returns 404 on any mismatch.
        $r->addRoute('GET', '/api/v1/public/media/{id}', [PublicMediaController::class, 'show'], []);

        // Agent Templates — list/show/validate/import + per-agent export.
        // The {id:.+} regex lets the captured id contain slashes (the
        // namespaced form `<source>/<slug>`), so the API can be called
        // with the slash percent-encoded (e.g. core%2Fcore-assistant).
        $r->addRoute('GET', '/api/v1/agent-templates', [AgentTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agent-templates/{id:.+}', [AgentTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agent-templates/validate', [AgentTemplateController::class, 'validatePayload'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agent-templates/import', [AgentTemplateController::class, 'import'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agents/{id}/export', [AgentTemplateController::class, 'exportAgent'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/llm-drivers', [LLMConfigController::class, 'drivers'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/llm-configs', [LLMConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/llm-configs', [LLMConfigController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/llm-configs/global', [LLMConfigController::class, 'globalConfigs'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/llm-configs/{id}/set-default', [LLMConfigController::class, 'setDefault'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/memories', [MemoryController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/memories', [MemoryController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/memories/reorder', [MemoryController::class, 'reorder'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_MEMORIES_ID, [MemoryController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_MEMORIES_ID, [MemoryController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_MEMORIES_ID, [MemoryController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_MEMORIES_MEMORY_ID, [AgentMemoryController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_MEMORIES_MEMORY_ID, [AgentMemoryController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_MEMORIES_MEMORY_ID, [AgentMemoryController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/notifications', [NotificationController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/notifications', [NotificationController::class, 'destroyAll'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/notifications/{id}', [NotificationController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/me/profile', [UserProfileController::class, 'getProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/me/profile', [UserProfileController::class, 'putProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/me/locations', [UserProfileController::class, 'getLocations'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/me/locations', [UserProfileController::class, 'postLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/me/locations/{id}', [UserProfileController::class, 'putLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/me/locations/{id}', [UserProfileController::class, 'deleteLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/users', [UserController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/users', [UserController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_USERS_ID, [UserController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_USERS_ID, [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_USERS_ID, [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_USERS_ID, [UserController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', '/api/v1/users/{id}/roles', [UserController::class, 'listRoles'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/users/{id}/roles', [UserController::class, 'grantRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/users/{id}/roles/{role}', [UserController::class, 'revokeRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

        $r->addRoute('GET', '/api/v1/mail-config', [MailConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/mail-config', [MailConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/mail-config/test', [MailConfigController::class, 'test'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

        $r->addRoute('GET', '/api/v1/mail-templates', [MailTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/mail-templates', [MailTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', '/api/v1/mail-templates/{name}/preview', [MailTemplateController::class, 'preview'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

        $r->addRoute('GET', '/api/v1/sse/status', [SseController::class, 'status'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/sse/auth', [SseController::class, 'auth'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs/{runId}/trigger', [ScheduledRunController::class, 'trigger'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }
}
