<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentPictureController;
use Spora\Http\AgentTemplateController;
use Spora\Http\AgentToolController;
use Spora\Http\AgentTransferController;
use Spora\Http\AppsController;
use Spora\Http\AssetController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\GroupController;
use Spora\Http\GroupLlmConfigsController;
use Spora\Http\GroupMemberController;
use Spora\Http\GroupPictureController;
use Spora\Http\GroupPreferencesController;
use Spora\Http\GroupToolsController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MediaAllowedTypesController;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaDerivativeController;
use Spora\Http\MediaDerivativeOptionsController;
use Spora\Http\MediaUploadController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Http\NotificationSubscriptionController;
use Spora\Http\PluginsController;
use Spora\Http\PrincipalController;
use Spora\Http\PromptTemplateController;
use Spora\Http\PublicMediaController;
use Spora\Http\RetryChainController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SkillController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\TaskTickController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;
use Spora\Http\WorkerController;
use Spora\OpenApi\RouteSpecCollector;

final class RouteDefinitions
{
    public const ROUTE_MEDIA_ITEM = '/api/v1/media/{id}';

    public const ROUTE_AGENTS_ID = '/api/v1/agents/{id}';
    public const ROUTE_AGENTS_TRANSFER = '/api/v1/agents/{id}/transfer';
    public const ROUTE_AGENTS_TOOL_OVERRIDE = '/api/v1/agents/{id}/tools/{toolId}/override';
    public const ROUTE_TOOLS_SETTINGS = '/api/v1/tools/{toolId}/settings';
    public const ROUTE_TOOLS_USER_SETTINGS = '/api/v1/tools/{toolId}/user-settings';
    public const ROUTE_LLM_CONFIGS_ID = '/api/v1/llm-configs/{id}';
    public const ROUTE_USERS_ID = '/api/v1/users/{id}';
    public const ROUTE_MAIL_TEMPLATES_ID = '/api/v1/mail-templates/{id}';
    public const ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID = '/api/v1/agents/{id}/templates/{templateId}';
    public const ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID = '/api/v1/agents/{id}/scheduled-runs/{runId}';
    public const ROUTE_SKILLS_SLUG = '/api/v1/skills/{slug}';
    public const ROUTE_GROUPS = '/api/v1/groups';
    public const ROUTE_GROUPS_ID = '/api/v1/groups/{id}';
    public const ROUTE_GROUPS_ID_AGENTS = self::ROUTE_GROUPS_ID . '/agents';
    public const ROUTE_GROUPS_ID_MEMBERS = self::ROUTE_GROUPS_ID . '/members';
    public const ROUTE_GROUPS_ID_MEMBERS_UID = self::ROUTE_GROUPS_ID_MEMBERS . '/{uid}';
    public const ROUTE_GROUPS_ID_PREFERENCES = self::ROUTE_GROUPS_ID . '/preferences';
    public const ROUTE_GROUPS_ID_TOOLS = self::ROUTE_GROUPS_ID . '/tools';
    public const ROUTE_GROUPS_ID_TOOLS_CLASS = self::ROUTE_GROUPS_ID_TOOLS . '/{toolClass}';
    public const ROUTE_GROUPS_ID_LLM_CONFIGS = self::ROUTE_GROUPS_ID . '/llm-configs';
    public const ROUTE_GROUPS_ID_LLM_CONFIGS_CID = self::ROUTE_GROUPS_ID_LLM_CONFIGS . '/{cid}';
    public const ROUTE_GROUPS_ID_LLM_CONFIGS_CID_SET_DEFAULT = self::ROUTE_GROUPS_ID_LLM_CONFIGS_CID . '/set-default';
    public const ROUTE_GROUPS_ID_PICTURE_IMAGE = self::ROUTE_GROUPS_ID . '/picture/image';

    public const ROUTE_NOTIFICATIONS_SUBSCRIPTIONS = '/api/v1/notifications/subscriptions';

    public static function register(MiddlewareRouteCollector | RouteSpecCollector $r, array $config = []): void
    {
        self::registerCoreRoutes($r);
        self::registerAssetRoutes($r);
        self::registerPluginRoutes($r);
        self::registerAuthRoutes($r);
        self::registerAgentRoutes($r);
        self::registerGroupRoutes($r, $config);
        self::registerAgentPictureRoutes($r);
        self::registerToolRoutes($r);
        self::registerTaskRoutes($r);
        self::registerMediaRoutes($r);
        self::registerTemplateRoutes($r);
        self::registerSkillRoutes($r);
        self::registerLlmConfigRoutes($r);
        self::registerPreferenceRoutes($r);
        self::registerUserProfileRoutes($r);
        self::registerUserRoutes($r);
        self::registerMailRoutes($r);
        self::registerPromptTemplateRoutes($r);
        self::registerScheduledRunRoutes($r);
    }

    private static function registerCoreRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/health', [HealthController::class, 'check'], []);
        $r->addRoute('GET', '/api/v1/config', [ConfigController::class, 'index'], []);
        $r->addRoute('GET', '/api/v1/apps', [AppsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerAssetRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Asset serving — authenticated; the controller enforces ownership
        // (asset.task.user_id == currentUserId, with admin bypass). The
        // URL is no longer the authorization token because the new
        // opaque-URL form (`/api/v1/assets/<uuid>`) uses the row's
        // primary key as the URL component, so anyone with the URL
        // could otherwise fetch the bytes.
        $r->addRoute('GET', '/api/v1/assets/{filename}', [AssetController::class, 'show'], [AuthMiddleware::class]);
    }

    private static function registerPluginRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/plugins', [PluginsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        // Plugin catalog (Packagist browse) — Auth only. Read-only, so no Csrf.
        // Admin not required: any logged-in user can browse. The controller
        // returns 404 when SPORA_PLUGIN_CATALOG_ENABLED=false so the navbar
        // item can hide cleanly.
        $r->addRoute('GET', '/api/v1/plugins/catalog', [PluginsController::class, 'catalog'], [AuthMiddleware::class]);
        $r->addRoute('POST', '/api/v1/plugins', [PluginsController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/plugins/{package}', [PluginsController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/plugins/{package}', [PluginsController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    }

    private static function registerAuthRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('POST', '/api/v1/auth/login', [AuthController::class, 'login'], []);
        $r->addRoute('POST', '/api/v1/auth/register', [AuthController::class, 'register'], []);
        // Authenticated + CSRF: the controllers dereference the current user, so
        // AuthMiddleware must run before CsrfMiddleware (CsrfMiddleware itself only
        // validates the token header and assumes a session-bearing request).
        $r->addRoute('POST', '/api/v1/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/auth/me', [AuthController::class, 'me'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/auth/password', [AuthController::class, 'password'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', '/api/v1/auth/account', [AuthController::class, 'account'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/auth/verify/{selector}', [AuthController::class, 'verify'], []);
        $r->addRoute('POST', '/api/v1/auth/verification/resend', [AuthController::class, 'resendVerification'], []);
        $r->addRoute('POST', '/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword'], []);
        $r->addRoute('POST', '/api/v1/auth/reset-password', [AuthController::class, 'resetPassword'], []);
        $r->addRoute('POST', '/api/v1/auth/email/change-request', [AuthController::class, 'requestEmailChange'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/auth/email/confirm', [AuthController::class, 'confirmEmailChange'], []);
    }

    private static function registerAgentRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/agents', [AgentController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents', [AgentController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_ID, [AgentController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_AGENTS_ID, [AgentController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_ID, [AgentController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/favorite', [AgentController::class, 'favorite'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/agents/{id}/favorite', [AgentController::class, 'unfavorite'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_AGENTS_TRANSFER, [AgentTransferController::class, 'transferPrincipal'], [AuthMiddleware::class, CsrfMiddleware::class]);

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
    }

    private static function registerGroupRoutes(MiddlewareRouteCollector | RouteSpecCollector $r, array $config = []): void
    {
        // Groups + members + principal-discovery. Group writes are admin-only;
        // member writes accept admin OR group-owner (the controller enforces
        // the owner branch via GroupService::fetchCallerRole).
        //
        // POST opens up to every authenticated caller when
        // `config#allow_group_creation` is true (default; env override
        // `SPORA_ALLOW_GROUP_CREATION`). PATCH/DELETE stay admin-only —
        // "create" is platform-wide governance, "modify" stays per-group
        // RBAC. The SPA mirrors this via the `/api/v1/config` endpoint
        // (`allow_group_creation`) so it knows whether to surface the
        // Create-group button on MyGroupsPage.
        $createMiddleware = ($config['allow_group_creation'] ?? true) === true
            ? [AuthMiddleware::class, CsrfMiddleware::class]
            : [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class];
        $r->addRoute('POST', self::ROUTE_GROUPS, [GroupController::class, 'store'], $createMiddleware);
        $r->addRoute('GET', self::ROUTE_GROUPS, [GroupController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_GROUPS_ID, [GroupController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_GROUPS_ID, [GroupController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_GROUPS_ID, [GroupController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_GROUPS_ID_MEMBERS, [GroupMemberController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_GROUPS_ID_MEMBERS, [GroupMemberController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_GROUPS_ID_MEMBERS_UID, [GroupMemberController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_GROUPS_ID_MEMBERS_UID, [GroupMemberController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Group settings pages (Overview / Agents / Tools / LLM Drivers / Preferences).
        // All routes use AuthMiddleware + CsrfMiddleware; the per-action
        // `callerCanManageGroup()` does the write gate (owner / admin / global admin),
        // and `callerCanSeeGroup()` does the read gate (member / owner / admin / global admin).
        // Non-members receive 404 (existence-hiding, not 403).
        $r->addRoute('GET', self::ROUTE_GROUPS_ID_AGENTS, [GroupController::class, 'agents'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_GROUPS_ID_PREFERENCES, [GroupPreferencesController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_GROUPS_ID_PREFERENCES, [GroupPreferencesController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_GROUPS_ID_TOOLS, [GroupToolsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_GROUPS_ID_TOOLS_CLASS, [GroupToolsController::class, 'upsert'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_GROUPS_ID_TOOLS_CLASS, [GroupToolsController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_GROUPS_ID_LLM_CONFIGS, [GroupLlmConfigsController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_GROUPS_ID_LLM_CONFIGS, [GroupLlmConfigsController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_GROUPS_ID_LLM_CONFIGS_CID, [GroupLlmConfigsController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_GROUPS_ID_LLM_CONFIGS_CID, [GroupLlmConfigsController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_GROUPS_ID_LLM_CONFIGS_CID_SET_DEFAULT, [GroupLlmConfigsController::class, 'setDefault'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Group picture — image upload + delete. Avatar-only fields
        // (archetype/variant_key/palette_key) ride on PATCH /api/v1/groups/{id}
        // via the `profile_picture` nested object — only the image-file
        // path needs a multipart endpoint. Auth gate is owner-or-admin,
        // enforced in {@see GroupPictureController}.
        $r->addRoute('POST', self::ROUTE_GROUPS_ID_PICTURE_IMAGE, [GroupPictureController::class, 'uploadImage'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_GROUPS_ID_PICTURE_IMAGE, [GroupPictureController::class, 'deleteImage'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/principals/me', [PrincipalController::class, 'currentForUser'], [AuthMiddleware::class]);
    }

    private static function registerAgentPictureRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Agent picture — image upload + delete. Avatar-only fields
        // (archetype/variant_key/palette_key) ride on PATCH /api/v1/agents/{id}
        // via the `profile_picture` nested object — only the image-file
        // path needs a multipart endpoint.
        $r->addRoute('POST', '/api/v1/agents/{id}/picture/image', [AgentPictureController::class, 'uploadImage'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/agents/{id}/picture/image', [AgentPictureController::class, 'deleteImage'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerToolRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/tools', [ToolController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'getSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'putSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_TOOLS_SETTINGS, [ToolController::class, 'deleteSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'getUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'putUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_TOOLS_USER_SETTINGS, [ToolController::class, 'deleteUserSettings'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerTaskRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/tasks', [TaskController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks', [TaskController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/tasks/{taskId}', [TaskController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/approve', [TaskController::class, 'approve'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/reject', [TaskController::class, 'reject'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/retry', [TaskController::class, 'retry'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/continue', [TaskController::class, 'continue'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/abort', [TaskController::class, 'abort'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/abort-sub-agent', [TaskController::class, 'abortSubAgent'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/tasks/{taskId}/tick', [TaskTickController::class, 'tick'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/tasks/{taskId}/retry-chain', [RetryChainController::class, 'cancelRetryChain'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/tasks/{taskId}', [TaskController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Client-worker mode housekeeping lives with the task routes since
        // it drives task ticks on the worker's behalf. Gated inline on
        // worker_runtime_mode (matches PluginsController::catalog's pattern)
        // so server-mode installs get a clean 404.
        $r->addRoute('POST', '/api/v1/worker/housekeeping', [WorkerController::class, 'housekeeping'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerMediaRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Media Archive — read surface for the composer picker and the
        // operator dashboard. Plugin tools write rows via
        // MediaArchiveService::ingest(); the upload endpoint also lives
        // here so the composer can drop a file without depending on the
        // Media Archive plugin. The four admin routes
        // (`show`/`update`/`destroy`/`public-token/refresh`) moved to
        // `spora-plugin-media-archive/src/Http/MediaArchiveAdminController`
        // so the plugin owns its CRUD end-to-end, mirroring the
        // `spora-plugin-memories` pattern.
        $r->addRoute('GET', '/api/v1/media', [MediaArchiveController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/media/allowed-types', [MediaAllowedTypesController::class, 'index'], [AuthMiddleware::class]);
        $r->addRoute('POST', '/api/v1/media', [MediaUploadController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Media derivatives — generic surface for any plugin (Typst, OCR,
        // …) to publish a derivative of a media asset. Generic on purpose:
        // multiple consumers (the Media Archive plugin's VersionsStrip,
        // the composer, future admin surfaces), no plugin-specific logic.
        // Mirrors the architectural rationale for keeping
        // `/api/v1/media/allowed-types` in core.
        $r->addRoute('POST', self::ROUTE_MEDIA_ITEM . '/derivatives', [MediaDerivativeController::class, 'create'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_MEDIA_ITEM . '/derivatives/options', [MediaDerivativeOptionsController::class, 'index'], [AuthMiddleware::class]);

        // Public, token-gated media access. No auth middleware — the token
        // itself is the credential. The id is always a UUID shape; the
        // controller returns 404 on any mismatch.
        $r->addRoute('GET', '/api/v1/public/media/{id}', [PublicMediaController::class, 'show'], []);
    }

    private static function registerTemplateRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Agent Templates — list/show/validate/import + per-agent export.
        // The {id:.+} regex lets the captured id contain slashes (the
        // namespaced form `<source>/<slug>`), so the API can be called
        // with the slash percent-encoded (e.g. core%2Fcore-assistant).
        $r->addRoute('GET', '/api/v1/agent-templates', [AgentTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agent-templates/{id:.+}', [AgentTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agent-templates/validate', [AgentTemplateController::class, 'validatePayload'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agent-templates/import', [AgentTemplateController::class, 'import'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/agents/{id}/export', [AgentTemplateController::class, 'exportAgent'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerSkillRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Skills — list + detail. The list powers the Skill tool's
        // `allowed_skills` multi-select `dataSource`; the detail
        // endpoint surfaces the full SKILL.md body and sidecar listing
        // for the admin UI's skill-detail view.
        $r->addRoute('GET', '/api/v1/skills', [SkillController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_SKILLS_SLUG, [SkillController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerLlmConfigRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/llm-drivers', [LLMConfigController::class, 'drivers'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/llm-configs', [LLMConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/llm-configs', [LLMConfigController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        // GET /llm-configs/global is intentionally NOT admin-gated: the same
        // payload is used by `LLMConfigController::index()` (which every
        // authenticated caller already accesses) for default-config resolution
        // on the agent composer. The write/management side stays admin-only via
        // `POST /llm-configs/.../set-default` + `POST /llm-configs` semantics.
        $r->addRoute('GET', '/api/v1/llm-configs/global', [LLMConfigController::class, 'globalConfigs'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_LLM_CONFIGS_ID, [LLMConfigController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/llm-configs/{id}/set-default', [LLMConfigController::class, 'setDefault'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerPreferenceRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/user-preferences/llm', [UserPreferenceController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/notifications', [NotificationController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/notifications', [NotificationController::class, 'destroyAll'], [AuthMiddleware::class, CsrfMiddleware::class]);

        // Static subscription routes (collection-level) must be
        // registered before the variable `/{id}` routes below so
        // fast-route's regex-based dispatcher does not shadow them
        // with a catch-all.
        $r->addRoute('GET', self::ROUTE_NOTIFICATIONS_SUBSCRIPTIONS, [NotificationSubscriptionController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', self::ROUTE_NOTIFICATIONS_SUBSCRIPTIONS, [NotificationSubscriptionController::class, 'subscribe'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_NOTIFICATIONS_SUBSCRIPTIONS, [NotificationSubscriptionController::class, 'unsubscribe'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/notifications/{id}', [NotificationController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerUserProfileRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/me/profile', [UserProfileController::class, 'getProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/me/profile', [UserProfileController::class, 'putProfile'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/me/locations', [UserProfileController::class, 'getLocations'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/me/locations', [UserProfileController::class, 'postLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/me/locations/{id}', [UserProfileController::class, 'putLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/me/locations/{id}', [UserProfileController::class, 'deleteLocation'], [AuthMiddleware::class, CsrfMiddleware::class]);

        $r->addRoute('GET', '/api/v1/sse/status', [SseController::class, 'status'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/sse/auth', [SseController::class, 'auth'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', '/api/v1/sse/authorize', [SseController::class, 'authorize'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerUserRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/users', [UserController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/users', [UserController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_USERS_ID, [UserController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_USERS_ID, [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PATCH', self::ROUTE_USERS_ID, [UserController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_USERS_ID, [UserController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', '/api/v1/users/{id}/roles', [UserController::class, 'listRoles'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/users/{id}/roles', [UserController::class, 'grantRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', '/api/v1/users/{id}/roles/{role}', [UserController::class, 'revokeRole'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    }

    private static function registerMailRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/mail-config', [MailConfigController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', '/api/v1/mail-config', [MailConfigController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/mail-config/test', [MailConfigController::class, 'test'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', '/api/v1/mail-templates', [MailTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('POST', '/api/v1/mail-templates', [MailTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', '/api/v1/mail-templates/{name}/preview', [MailTemplateController::class, 'preview'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_MAIL_TEMPLATES_ID, [MailTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class]);
    }

    private static function registerPromptTemplateRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/templates', [PromptTemplateController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID, [PromptTemplateController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }

    private static function registerScheduledRunRoutes(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        $r->addRoute('GET', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'index'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs', [ScheduledRunController::class, 'store'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('GET', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'show'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('PUT', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'update'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('DELETE', self::ROUTE_AGENTS_SCHEDULED_RUNS_RUN_ID, [ScheduledRunController::class, 'destroy'], [AuthMiddleware::class, CsrfMiddleware::class]);
        $r->addRoute('POST', '/api/v1/agents/{id}/scheduled-runs/{runId}/trigger', [ScheduledRunController::class, 'trigger'], [AuthMiddleware::class, CsrfMiddleware::class]);
    }
}
