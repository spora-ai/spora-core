<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\AgentTemplates\Exceptions\AgentImportFailedException;
use Spora\AgentTemplates\Exceptions\AgentTemplateNotFoundException;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;

/**
 * Applies an Agent Template to the database: orchestrates the creation of a
 * new Agent row, delegates per-tool writes to {@see AgentTemplateToolsApplier},
 * and writes per-operation auto-approve overrides.
 *
 * Non-password settings may be written from an operator-exported template
 * through ToolConfigService's agent-override path. Missing required settings
 * still produce TOOL_NEEDS_CONFIGURATION warnings.
 *
 * Plugins are NEVER auto-installed. Each entry of `required_plugins` is
 * a Composer `vendor/name` package string (e.g. `spora-ai/spora-plugin-minimax`);
 * the importer resolves it to the installed plugin's slug via
 * {@see PluginLoader::getSlugForPackageName()} and emits a `PLUGIN_MISSING`
 * warning when no loaded plugin declares that package. The import is not aborted.
 */
final class AgentTemplateImporter
{
    /**
     * Inline warning surfaced on every export response so operators
     * don't accidentally ship credentials in a template file.
     */
    public const SETTINGS_NOT_EXPORTED_WARNING = 'Settings (passwords, API keys) are NOT included in this export. Recipients must configure them in Settings → Tools after importing.';

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        private readonly PluginLoader $plugins,
        private readonly Paths $paths,
        private readonly AgentTemplateToolsApplier $toolsApplier,
        private readonly AgentTemplateAgentCreator $agentCreator,
        private readonly ?AgentPictureService $pictureService = null,
    ) {}

    /**
     * Look up a built-in template by id and apply it.
     *
     * @throws AgentTemplateNotFoundException when the template id is unknown.
     */
    public function applyTemplate(int $userId, string $templateId, ?int $principalId = null): ImportResult
    {
        $scanner = new AgentTemplateScanner(
            directories: $this->collectDirectories(),
        );

        foreach ($scanner->scan() as $template) {
            if ($template->id() === $templateId) {
                return $this->apply($userId, $template, $principalId);
            }
        }

        throw new AgentTemplateNotFoundException("Agent template '{$templateId}' not found.");
    }

    /**
     * Apply a raw payload (from the upload endpoint). The caller is
     * expected to have already validated it via {@see AgentTemplateValidator}.
     *
     * @param array<string, mixed> $raw
     */
    public function importPayload(int $userId, array $raw, ?int $principalId = null): ImportResult
    {
        $template = new AgentTemplate(raw: $raw, source: 'uploaded');
        return $this->apply($userId, $template, $principalId);
    }

    /**
     * Internal: apply an AgentTemplate to the database. The implementation
     * is split into small helpers so each method stays under the cognitive
     * complexity ceiling; the orchestration lives here.
     *
     * @throws AgentImportFailedException when the post-insert sanity check fails.
     */
    private function apply(int $userId, AgentTemplate $template, ?int $principalId = null): ImportResult
    {
        $warnings = $template->warnings();
        $registeredTools = $this->toolConfig->getRegisteredToolClasses();

        $this->collectPluginWarnings($template, $warnings);

        $resolvedPrincipalId = $this->resolvePrincipalId($userId, $principalId);

        // The closure returns a tuple (agentId, toolsEnabled) so the
        // outer scope can unpack both without a by-ref parameter on
        // applyTools. Skip the tools-application step when the payload
        // has no `tools` block — the LLM-facing create_agent flow runs
        // this path and applies the toolset separately via configure_tools.
        [$agentId, $toolsEnabled] = Capsule::connection()->transaction(
            function () use ($resolvedPrincipalId, $template, $registeredTools, &$warnings): array {
                $agentId = $this->agentCreator->create($resolvedPrincipalId, $template);
                $toolsEnabled = array_key_exists('tools', $template->raw())
                    ? $this->toolsApplier->applyTools($agentId, $template, $registeredTools, $warnings)
                    : [];
                return [$agentId, $toolsEnabled];
            },
        );

        $agent = Agent::find($agentId);
        if ($agent === null) {
            throw new AgentImportFailedException("Agent {$agentId} disappeared mid-import.");
        }

        $this->applyPictureMetadata($agentId, $template, $warnings);

        return new ImportResult(
            agent: $agent,
            toolsEnabled: $toolsEnabled,
            warnings: $warnings,
        );
    }

    /**
     * Resolve the principal id for a new agent. When the caller
     * passes an explicit principalId, use it directly; otherwise
     * materialise the operator's user-principal so the import
     * succeeds even on a fresh install whose seed step hasn't yet
     * run.
     */
    private function resolvePrincipalId(int $userId, ?int $principalId): int
    {
        if ($principalId !== null && $principalId > 0) {
            return $principalId;
        }

        return (int) (new PrincipalService(new PrincipalResolver()))
            ->ensureUserPrincipal($userId)
            ->id;
    }

    /**
     * Apply the `metadata.archetype` / `variant_key` / `palette_key`
     * fields to the new agent's picture row. Unknown archetype / palette
     * values are already surfaced as warnings by {@see AgentTemplateValidator}
     * before this method runs; we silently skip them here so the import
     * still creates a usable agent (with the default picture).
     *
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    private function applyPictureMetadata(int $agentId, AgentTemplate $template, array &$warnings): void
    {
        if ($this->pictureService === null) {
            return;
        }

        $metadata = $template->raw()['metadata'] ?? null;
        if (!is_array($metadata)) {
            return;
        }

        $pictureFields = array_intersect_key($metadata, array_flip(['archetype', 'variant_key', 'palette_key']));
        if ($pictureFields === []) {
            return;
        }

        try {
            $this->pictureService->applyTemplateMetadata($agentId, $pictureFields);
        } catch (InvalidArgumentException $e) {
            $warnings[] = [
                'code'     => 'PICTURE_METADATA_INVALID',
                'severity' => 'warning',
                'message'  => $e->getMessage(),
                'path'     => 'metadata',
            ];
        }
    }

    /**
     * Aggregate PLUGIN_MISSING warnings for any `required_plugins` Composer
     * package name that does not resolve to a loaded plugin. Each entry is
     * a `vendor/name` string (e.g. `spora-ai/spora-plugin-minimax`); the
     * importer resolves it to the on-disk slug via
     * {@see PluginLoader::getSlugForPackageName()} before comparing
     * against the installed set. Non-fatal — operators install plugins
     * manually.
     *
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    private function collectPluginWarnings(AgentTemplate $template, array &$warnings): void
    {
        foreach ($template->requiredPlugins() as $package) {
            if ($this->plugins->getSlugForPackageName($package) !== null) {
                continue;
            }
            $warnings[] = [
                'code'     => 'PLUGIN_MISSING',
                'severity' => 'warning',
                'message'  => sprintf("Plugin '%s' is required but not installed.", $package),
                'path'     => 'required_plugins',
            ];
        }
    }

    /**
     * Aggregate directories: project overrides win over framework default,
     * plus everything contributed by loaded plugins.
     *
     * @return list<string>
     */
    private function collectDirectories(): array
    {
        $dirs = [];
        foreach ($this->paths->agentTemplatesPaths() as $p) {
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }
        foreach ($this->plugins->agentTemplatePaths() as $p) {
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }
        return $dirs;
    }
}
