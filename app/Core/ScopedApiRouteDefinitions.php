<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\Middleware\GlobalApiKeyMiddleware;
use Spora\Http\Middleware\GroupApiKeyMiddleware;
use Spora\Http\ScopedApi\ScopedApiAgentController;
use Spora\Http\ScopedApi\ScopedApiChatActionController;
use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Http\ScopedApi\ScopedApiGroupController;
use Spora\Http\ScopedApi\ScopedApiKeyController;
use Spora\Http\ScopedApi\ScopedApiMediaController;
use Spora\OpenApi\RouteSpecCollector;

/**
 * The group-scoped and global-scoped API surfaces.
 *
 * Lives outside {@see RouteDefinitions} to keep that class under the
 * 20-method Sonar brain-overload threshold, and beside
 * {@see AgentApiRouteDefinitions} for the same reason.
 *
 * The bearer surfaces are registered only when their scope is switched on,
 * and the condition is re-read per request: the settings are database-backed
 * and `Kernel::handle()` applies the settings overlay before
 * `buildRouter()`, so a toggle takes effect on the next request with no
 * restart. An operator who has not enabled a scope therefore gets a plain
 * 404 from the router — the route does not exist, rather than existing and
 * declining, which is what keeps the surface from being enumerable.
 *
 * The three scopes are independent: `SPORA_GROUP_API` mounts the group
 * surface, `SPORA_GLOBAL_API` the global one, and neither implies the
 * agent surface. There is no master switch, so enabling one scope never
 * silently opens another.
 *
 * `RouteSpecCollector` is exempt from the gate. The spec has to describe the
 * API regardless of whether the machine generating it happens to have the
 * feature switched on, and it is not a live dispatcher.
 *
 * Two auth stacks, deliberately different:
 *
 *   - `/api/v1/group-api/*` and `/api/v1/global-api/*` are the bearer
 *     middleware alone. A bearer key is the only credential; no CSRF token,
 *     no session cookie, and no fallback to one. Each accepts one scope: a
 *     global key is not a group key.
 *   - `/api/v1/groups/{groupId}/api-keys*` and `/api/v1/global-api-keys*`
 *     are the ordinary admin stack (`AuthMiddleware` + `CsrfMiddleware`),
 *     because that is where an operator mints keys. Always registered, so
 *     a key can be revoked even on an instance whose scope is switched off.
 *
 * Endpoints (the group surface):
 *
 *   GET    /api/v1/group-api/group                    — the key's group
 *   GET    /api/v1/group-api/agents                   — list the group's agents
 *   POST   /api/v1/group-api/agents                   — create an agent
 *   GET    /api/v1/group-api/agents/{agentId}         — read an agent
 *   PATCH  /api/v1/group-api/agents/{agentId}         — update an agent
 *   DELETE /api/v1/group-api/agents/{agentId}         — delete an agent
 *   GET    /api/v1/group-api/chats                    — list the group's chats
 *   POST   /api/v1/group-api/chats                    — start a chat
 *   GET    /api/v1/group-api/chats/{chatId}/status    — status + answer
 *   GET    /api/v1/group-api/chats/{chatId}/messages  — conversation
 *   POST   /api/v1/group-api/chats/{chatId}/messages  — continue
 *   GET    /api/v1/group-api/chats/{chatId}/transcript— full detail
 *   POST   /api/v1/group-api/chats/{chatId}/approve   — unblock approval
 *   POST   /api/v1/group-api/chats/{chatId}/reject    — unblock approval
 *   POST   /api/v1/group-api/chats/{chatId}/answer    — answer a question
 *   POST   /api/v1/group-api/media                    — upload a file
 *   GET    /api/v1/group-api/media/{mediaId}          — read a file back
 *
 * Endpoints (the global surface): the same, under `/api/v1/global-api/*`,
 * plus the group routes:
 *
 *   GET    /api/v1/global-api/groups                  — list every group
 *   POST   /api/v1/global-api/groups                  — create a group
 *   GET    /api/v1/global-api/groups/{groupId}        — read a group
 *   PATCH  /api/v1/global-api/groups/{groupId}        — update a group
 *   DELETE /api/v1/global-api/groups/{groupId}        — delete a group
 *
 * No route here is a `{chatId}` catch-all: every chat path ends in a
 * distinct literal, so FastRoute — which hash-matches static routes ahead
 * of variable ones — cannot read `status` as a `{chatId}`.
 */
final class ScopedApiRouteDefinitions
{
    /** The bearer surface a group key drives. */
    private const GROUP_PREFIX = '/api/v1/group-api';

    /** The bearer surface a global key drives. */
    private const GLOBAL_PREFIX = '/api/v1/global-api';

    /** The agent path, relative to a surface's prefix. */
    private const AGENT_PATH = '/agents/{agentId}';

    /** The group path, relative to the global surface's prefix. */
    private const GROUP_PATH = '/groups/{groupId}';

    public static function register(MiddlewareRouteCollector | RouteSpecCollector $r, ?Config $config = null): void
    {
        $admin = [AuthMiddleware::class, CsrfMiddleware::class];
        $keys = ScopedApiKeyController::class;

        $r->addRoute('GET', '/api/v1/groups/{groupId}/api-keys', [$keys, 'groupIndex'], $admin);
        $r->addRoute('POST', '/api/v1/groups/{groupId}/api-keys', [$keys, 'groupStore'], $admin);
        $r->addRoute('DELETE', '/api/v1/groups/{groupId}/api-keys/{keyId}', [$keys, 'groupDestroy'], $admin);
        $r->addRoute('GET', '/api/v1/global-api-keys', [$keys, 'globalIndex'], $admin);
        $r->addRoute('POST', '/api/v1/global-api-keys', [$keys, 'globalStore'], $admin);
        $r->addRoute('DELETE', '/api/v1/global-api-keys/{keyId}', [$keys, 'globalDestroy'], $admin);

        if (self::enabled($r, $config, 'SPORA_GROUP_API')) {
            self::registerScopedSurface($r, self::GROUP_PREFIX, GroupApiKeyMiddleware::class);
            $r->addRoute('GET', self::GROUP_PREFIX . '/group', [ScopedApiGroupController::class, 'ownGroup'], [GroupApiKeyMiddleware::class]);
        }

        if (self::enabled($r, $config, 'SPORA_GLOBAL_API')) {
            self::registerScopedSurface($r, self::GLOBAL_PREFIX, GlobalApiKeyMiddleware::class);
            $groups = ScopedApiGroupController::class;
            $r->addRoute('GET', self::GLOBAL_PREFIX . '/groups', [$groups, 'index'], [GlobalApiKeyMiddleware::class]);
            $r->addRoute('POST', self::GLOBAL_PREFIX . '/groups', [$groups, 'store'], [GlobalApiKeyMiddleware::class]);
            $r->addRoute('GET', self::GLOBAL_PREFIX . self::GROUP_PATH, [$groups, 'show'], [GlobalApiKeyMiddleware::class]);
            $r->addRoute('PATCH', self::GLOBAL_PREFIX . self::GROUP_PATH, [$groups, 'update'], [GlobalApiKeyMiddleware::class]);
            $r->addRoute('DELETE', self::GLOBAL_PREFIX . self::GROUP_PATH, [$groups, 'destroy'], [GlobalApiKeyMiddleware::class]);
        }
    }

    /**
     * The routes both scoped surfaces share, under one prefix.
     *
     * @param MiddlewareRouteCollector|RouteSpecCollector $r
     * @param class-string $middleware the bearer middleware that pins the scope
     */
    private static function registerScopedSurface(
        MiddlewareRouteCollector | RouteSpecCollector $r,
        string $prefix,
        string $middleware,
    ): void {
        $bearer = [$middleware];

        $agent = ScopedApiAgentController::class;
        $chat = ScopedApiChatController::class;
        $action = ScopedApiChatActionController::class;
        $media = ScopedApiMediaController::class;

        $r->addRoute('GET', $prefix . '/agents', [$agent, 'index'], $bearer);
        $r->addRoute('POST', $prefix . '/agents', [$agent, 'store'], $bearer);
        $r->addRoute('GET', $prefix . self::AGENT_PATH, [$agent, 'show'], $bearer);
        $r->addRoute('PATCH', $prefix . self::AGENT_PATH, [$agent, 'update'], $bearer);
        $r->addRoute('DELETE', $prefix . self::AGENT_PATH, [$agent, 'destroy'], $bearer);

        $r->addRoute('GET', $prefix . '/chats', [$chat, 'index'], $bearer);
        $r->addRoute('POST', $prefix . '/chats', [$chat, 'store'], $bearer);
        $r->addRoute('GET', $prefix . '/chats/{chatId}/status', [$chat, 'status'], $bearer);
        $r->addRoute('GET', $prefix . '/chats/{chatId}/messages', [$chat, 'messages'], $bearer);
        $r->addRoute('POST', $prefix . '/chats/{chatId}/messages', [$chat, 'continueChat'], $bearer);
        $r->addRoute('GET', $prefix . '/chats/{chatId}/transcript', [$chat, 'transcript'], $bearer);
        $r->addRoute('POST', $prefix . '/chats/{chatId}/approve', [$action, 'approve'], $bearer);
        $r->addRoute('POST', $prefix . '/chats/{chatId}/reject', [$action, 'reject'], $bearer);
        $r->addRoute('POST', $prefix . '/chats/{chatId}/answer', [$action, 'answer'], $bearer);

        $r->addRoute('POST', $prefix . '/media', [$media, 'store'], $bearer);
        $r->addRoute('GET', $prefix . '/media/{mediaId}', [$media, 'show'], $bearer);
    }

    private static function enabled(
        MiddlewareRouteCollector | RouteSpecCollector $r,
        ?Config $config,
        string $setting,
    ): bool {
        if ($r instanceof RouteSpecCollector) {
            return true;
        }

        // Fail closed when there is no config to consult, which only happens
        // in a test that forgot to pass one.
        return $config !== null
            && $config->getBool($setting)
            && $config->getString('SPORA_WORKER_RUNTIME_MODE') !== 'client';
    }
}
