<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Auth\ApiKeyIssuer;
use Spora\Auth\AuthService;
use Spora\Http\EffortProjection;
use Spora\Http\ScopedApi\ScopedApiAgentController;
use Spora\Http\ScopedApi\ScopedApiChatActionController;
use Spora\Http\ScopedApi\ScopedApiChatBody;
use Spora\Http\ScopedApi\ScopedApiChatController;
use Spora\Http\ScopedApi\ScopedApiEffortController;
use Spora\Http\ScopedApi\ScopedApiGroupController;
use Spora\Http\ScopedApi\ScopedApiKeyController;
use Spora\Http\ScopedApi\ScopedApiMediaController;
use Spora\Services\ApiKeys\ApiKeyAgentService;
use Spora\Services\ApiKeys\ApiKeyCallbackRecorder;
use Spora\Services\ApiKeys\ApiKeyChatAccess;
use Spora\Services\ApiKeys\ApiKeyChatProjection;
use Spora\Services\ApiKeys\ApiKeyFileIngest;
use Spora\Services\ApiKeys\ApiKeyGroupService;
use Spora\Services\ApiKeys\ApiKeyMediaAccess;
use Spora\Services\ApiKeys\ApiKeyReach;
use Spora\Services\AssetStreamer;
use Spora\Services\EffortService;
use Spora\Services\GroupService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
use Spora\Services\SsrfGuard;

/**
 * Stand-alone bindings for the group-scoped and global-scoped API surfaces.
 *
 * Lives in its own class to keep {@see ContainerDefinitions} under the
 * S1448 20-method ceiling — splitting the bindings out drops the
 * umbrella back to its pre-existing count.
 *
 * @return array<string, callable>
 */
final class ScopedApiContainerBindings
{
    /**
     * @return array<string, callable>
     */
    public static function all(): array
    {
        return [
            ApiKeyAgentService::class => static function (ContainerInterface $c): ApiKeyAgentService {
                return new ApiKeyAgentService(
                    $c->get(AgentTemplateImporter::class),
                    $c->get(AgentTemplateExporter::class),
                    $c->get(AgentTemplateValidator::class),
                    $c->get(PrincipalService::class),
                );
            },

            ApiKeyGroupService::class => static function (ContainerInterface $c): ApiKeyGroupService {
                return new ApiKeyGroupService(
                    $c->get(GroupService::class),
                    $c->get(PrincipalService::class),
                    $c->get(GroupPictureService::class),
                );
            },

            ScopedApiAgentController::class => static function (ContainerInterface $c): ScopedApiAgentController {
                return new ScopedApiAgentController(
                    $c->get(ApiKeyAgentService::class),
                    $c->get(ApiKeyReach::class),
                );
            },

            ScopedApiChatController::class => static function (ContainerInterface $c): ScopedApiChatController {
                return new ScopedApiChatController(
                    $c->get(ApiKeyChatAccess::class),
                    $c->get(ApiKeyChatProjection::class),
                    $c->get(ApiKeyFileIngest::class),
                    $c->get(ApiKeyCallbackRecorder::class),
                    $c->get(ApiKeyReach::class),
                    $c->get(SsrfGuard::class),
                    $c->get(Config::class),
                    $c->get(ScopedApiChatBody::class),
                    $c->get(EffortService::class),
                );
            },

            ScopedApiChatBody::class => static function (ContainerInterface $c): ScopedApiChatBody {
                return new ScopedApiChatBody(
                    $c->get(ApiKeyMediaAccess::class),
                );
            },

            ScopedApiEffortController::class => static function (ContainerInterface $c): ScopedApiEffortController {
                return new ScopedApiEffortController(
                    $c->get(ApiKeyReach::class),
                    $c->get(EffortProjection::class),
                );
            },

            ScopedApiChatActionController::class => static function (ContainerInterface $c): ScopedApiChatActionController {
                return new ScopedApiChatActionController(
                    $c->get(ApiKeyChatAccess::class),
                    $c->get(ApiKeyChatProjection::class),
                );
            },

            ScopedApiMediaController::class => static function (ContainerInterface $c): ScopedApiMediaController {
                return new ScopedApiMediaController(
                    $c->get(ApiKeyFileIngest::class),
                    $c->get(ApiKeyMediaAccess::class),
                    $c->get(ApiKeyReach::class),
                    $c->get(AssetStreamer::class),
                    $c->get(MediaAssetSerializer::class),
                );
            },

            ScopedApiGroupController::class => static function (ContainerInterface $c): ScopedApiGroupController {
                return new ScopedApiGroupController($c->get(ApiKeyGroupService::class));
            },

            ScopedApiKeyController::class => static function (ContainerInterface $c): ScopedApiKeyController {
                return new ScopedApiKeyController(
                    $c->get(AuthService::class),
                    $c->get(GroupService::class),
                    $c->get(ApiKeyIssuer::class),
                );
            },
        ];
    }
}
