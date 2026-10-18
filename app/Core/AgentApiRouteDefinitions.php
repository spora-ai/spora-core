<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\AgentApi\AgentApiChatActionController;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Http\AgentApi\AgentApiKeyController;
use Spora\Http\AgentApi\AgentApiMediaController;
use Spora\Http\Middleware\AgentApiKeyMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\OpenApi\RouteSpecCollector;

/**
 * The Agent API: a per-agent HTTP surface for external tools.
 *
 * Lives outside {@see RouteDefinitions} to keep that class under the
 * 20-method Sonar brain-overload threshold.
 *
 * The bearer surface is registered only when the feature is on, and the
 * condition is re-read per request: `SPORA_AGENT_API` is database-backed and
 * `Kernel::handle()` applies the settings overlay before `buildRouter()`, so
 * a toggle takes effect on the next request with no restart. An operator who
 * has not enabled it therefore gets a plain 404 from the router — the route
 * does not exist, rather than existing and declining, which is what keeps
 * the surface from being enumerable.
 *
 * `RouteSpecCollector` is exempt from the gate. The spec has to describe the
 * API regardless of whether the machine generating it happens to have the
 * feature switched on, and it is not a live dispatcher.
 *
 * Two auth stacks, deliberately different:
 *
 *   - `/api/v1/agent-api/*` is `AgentApiKeyMiddleware` alone. A bearer key is
 *     the only credential; no CSRF token, no session cookie, and no fallback
 *     to one.
 *   - `/api/v1/agents/{agentId}/api-keys*` is the ordinary admin stack
 *     (`AuthMiddleware` + `CsrfMiddleware`), because that is where an
 *     operator mints keys. Always registered, so the SPA's tab has
 *     something to call even on an instance with the feature off.
 *
 * Endpoints (the external surface):
 *
 *   GET    /api/v1/agent-api/chats                 — list this agent's chats
 *   POST   /api/v1/agent-api/chats                 — start a chat        (202)
 *   GET    /api/v1/agent-api/chats/{chatId}/status — status + answer
 *   GET    /api/v1/agent-api/chats/{chatId}/messages       — conversation
 *   POST   /api/v1/agent-api/chats/{chatId}/messages       — continue
 *   GET    /api/v1/agent-api/chats/{chatId}/transcript     — full detail
 *   POST   /api/v1/agent-api/chats/{chatId}/approve        — unblock approval
 *   POST   /api/v1/agent-api/chats/{chatId}/reject         — unblock approval
 *   POST   /api/v1/agent-api/chats/{chatId}/answer         — answer a question
 *   POST   /api/v1/agent-api/media                 — upload a file
 *   GET    /api/v1/agent-api/media/{mediaId}       — read a file back
 *
 * Endpoints (the admin surface):
 *
 *   GET    /api/v1/agents/{agentId}/api-keys
 *   POST   /api/v1/agents/{agentId}/api-keys      — returns the plaintext key once
 *   DELETE /api/v1/agents/{agentId}/api-keys/{keyId}
 *
 * FastRoute matches in registration order, so every literal segment below is
 * registered before the `{chatId}` catch-alls that would otherwise swallow
 * it — `status`, `messages`, `transcript`, `approve`, `reject` and `answer`
 * are all literals.
 */
final class AgentApiRouteDefinitions
{
    public static function register(MiddlewareRouteCollector | RouteSpecCollector $r, ?Config $config = null): void
    {
        $admin = [AuthMiddleware::class, CsrfMiddleware::class];

        $keys = AgentApiKeyController::class;

        $r->addRoute('GET', '/api/v1/agents/{agentId}/api-keys', [$keys, 'index'], $admin);
        $r->addRoute('POST', '/api/v1/agents/{agentId}/api-keys', [$keys, 'store'], $admin);
        $r->addRoute('DELETE', '/api/v1/agents/{agentId}/api-keys/{keyId}', [$keys, 'destroy'], $admin);

        if (!self::isEnabled($r, $config)) {
            return;
        }

        $bearer = [AgentApiKeyMiddleware::class];

        $chat = AgentApiChatController::class;
        $action = AgentApiChatActionController::class;
        $media = AgentApiMediaController::class;

        $r->addRoute('GET', '/api/v1/agent-api/chats', [$chat, 'index'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/chats', [$chat, 'store'], $bearer);
        $r->addRoute('GET', '/api/v1/agent-api/chats/{chatId}/status', [$chat, 'status'], $bearer);
        $r->addRoute('GET', '/api/v1/agent-api/chats/{chatId}/messages', [$chat, 'messages'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/chats/{chatId}/messages', [$chat, 'continueChat'], $bearer);
        $r->addRoute('GET', '/api/v1/agent-api/chats/{chatId}/transcript', [$chat, 'transcript'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/chats/{chatId}/approve', [$action, 'approve'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/chats/{chatId}/reject', [$action, 'reject'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/chats/{chatId}/answer', [$action, 'answer'], $bearer);
        $r->addRoute('POST', '/api/v1/agent-api/media', [$media, 'store'], $bearer);
        $r->addRoute('GET', '/api/v1/agent-api/media/{mediaId}', [$media, 'show'], $bearer);
    }

    private static function isEnabled(MiddlewareRouteCollector | RouteSpecCollector $r, ?Config $config): bool
    {
        if ($r instanceof RouteSpecCollector) {
            return true;
        }

        // Fail closed when there is no config to consult, which only happens
        // in a test that forgot to pass one.
        return $config !== null
            && $config->getBool('SPORA_AGENT_API')
            && $config->getString('SPORA_WORKER_RUNTIME_MODE') !== 'client';
    }
}
