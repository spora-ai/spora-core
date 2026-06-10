<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigService;
use Spora\Plugins\PluginLoader;

/**
 * Bundles the optional LLM-plumbing collaborators that the Orchestrator
 * threads through to its extracted services. Keeps the Orchestrator
 * constructor under the SonarQube `php:S107` 7-parameter cap.
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
        public readonly WorkerMode $workerMode = WorkerMode::Sync,
    ) {}
}
