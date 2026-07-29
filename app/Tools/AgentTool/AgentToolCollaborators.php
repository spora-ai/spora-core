<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Plugins\PluginLoader;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\ToolIconResolver;

/**
 * Optional helpers + framework collaborators that `AgentTool` consumes.
 *
 * Every member defaults to null and is constructed lazily on first
 * access — unit tests can omit the whole bundle (zero-arg constructor
 * is fine) or override one member at a time.
 */
final class AgentToolCollaborators
{
    public function __construct(
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?ToolIconResolver $iconResolver = null,
        private readonly ?NotesHandler $notesHandler = null,
        private readonly ?CatalogPresenter $catalogPresenter = null,
        private readonly ?ConfigurePlanner $configurePlanner = null,
        private readonly ?SlimPayloadValidator $payloadValidator = null,
        private readonly ?AgentTargetResolver $targetResolver = null,
    ) {}

    public function notesHandler(AgentServiceInterface $agentService): NotesHandler
    {
        return $this->notesHandler ?? new NotesHandler($agentService);
    }

    public function catalogPresenter(
        AgentServiceInterface $agentService,
        AgentToolSettingsServiceInterface $toolSettings,
    ): CatalogPresenter {
        return $this->catalogPresenter
            ?? new CatalogPresenter($agentService, $toolSettings, $this->pluginLoader, $this->iconResolver);
    }

    public function configurePlanner(AgentToolSettingsServiceInterface $toolSettings): ConfigurePlanner
    {
        return $this->configurePlanner ?? new ConfigurePlanner($toolSettings);
    }

    public function payloadValidator(): SlimPayloadValidator
    {
        return $this->payloadValidator ?? new SlimPayloadValidator();
    }

    public function targetResolver(): AgentTargetResolver
    {
        return $this->targetResolver ?? new AgentTargetResolver();
    }
}
