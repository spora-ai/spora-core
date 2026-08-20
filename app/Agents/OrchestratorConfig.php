<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigService;

/**
 * Bundles the optional LLM-plumbing collaborators that the Orchestrator
 * threads through to its extracted services.
 */
final class OrchestratorConfig
{
    /**
     * @param list<object> $toolInstances
     */
    public function __construct(
        public readonly array $toolInstances = [],
        public readonly ?LoggerInterface $logger = null,
        public readonly ?NotificationService $notificationService = null,
        public readonly ?PluginLoader $pluginLoader = null,
        public readonly ?MercurePublisherInterface $mercure = null,
        public readonly ?ToolConfigService $toolConfigService = null,
        public readonly ?ToolCallSerializer $toolCallSerializer = null,
        public readonly ?LLMConfigService $llmConfigService = null,
        // Source of calling-user_id for tool dispatch. The Orchestrator
        // resolves the calling user's id from the calling agent's row
        // — tools never receive a session-derived userId.
        public readonly ?AgentServiceInterface $agentService = null,
        public readonly ?SubAgentServiceInterface $subAgent = null,
        public readonly WorkerMode $workerMode = WorkerMode::Sync,
        public readonly ?LLMConfigPreferences $principalPreferences = null,
    ) {}
}
