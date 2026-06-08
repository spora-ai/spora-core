<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tool-resolver helpers for AgentToolController and AgentOverrideController.
 * Consumers must expose a `toolConfigService` property of type ToolConfigService.
 */
trait AgentControllerToolHelpers
{
    private function resolveToolClassFromRequest(Request $request): ?string
    {
        $toolId = (string) $request->attributes->get('toolId', '');

        if ($toolId === '') {
            return null;
        }

        /** @var ToolConfigService $toolConfig */
        $toolConfig = $this->toolConfigService;

        return $toolConfig->resolveToolClass($toolId);
    }
}
