<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;

/**
 * The media tool's `scope` setting now defaults to `principal`.
 *
 * The default lives in one place — the `#[ToolSetting]` attribute — and the
 * settings cascade always starts from it, so the effective value is read
 * through {@see ToolConfigService::getEffectiveSettings()}, never through
 * the attribute: the attribute is the input, the effective settings are
 * what run.
 *
 * The blast radius is narrow on purpose. Enabling a tool materialises the
 * schema default into the agent's override row, so an agent that already
 * had the `media` tool enabled keeps the value it was given and the default
 * change does not reach it. That is the property that makes the change safe
 * for an existing installation — and the reason the diagnostic scope
 * failure exists.
 */

/**
 * An agent owned by a user-principal, so the enable path can see it.
 */
function scopeDefaultAgent(int $userId): int
{
    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Scope default agent ' . bin2hex(random_bytes(3)),
        'is_active'    => true,
    ])->id;
}

function scopeDefaultToolConfig(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new NullLogger(),
        [MediaTool::class],
    );
}

function scopeDefaultSettingsService(ToolConfigService $toolConfig): AgentToolSettingsService
{
    return new AgentToolSettingsService(
        $toolConfig,
        new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        ),
    );
}

describe('the media tool scope default', function (): void {
    it('is principal for an agent with no scope value anywhere in the cascade', function (): void {
        $userId   = 51501;
        $agentId  = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('principal');
    });

    it('is principal for an agent that enables the tool from now on', function (): void {
        $userId     = 51502;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        scopeDefaultSettingsService($toolConfig)->enableTool($agentId, $userId, MediaTool::class);

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('principal');
    });

    it('stays agent for an agent that already had the tool enabled before the change', function (): void {
        // What the pre-change `enableTool()` wrote: the schema default of
        // the day, materialised into the agent's override row. The default
        // change does not reach it, so the agent keeps its reach.
        $userId     = 51503;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        AgentTool::create([
            'agent_id'   => $agentId,
            'tool_class' => MediaTool::class,
            'tool_name'  => 'media',
        ]);
        $toolConfig->putAgentOverride(MediaTool::class, $agentId, ['scope' => 'agent']);

        // The enable path runs again, as it does on every dashboard visit.
        scopeDefaultSettingsService($toolConfig)->enableTool($agentId, $userId, MediaTool::class);

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('agent');
    });

    it('keeps an operator\'s explicit scope', function (): void {
        $userId     = 51504;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        $toolConfig->putAgentOverride(MediaTool::class, $agentId, ['scope' => 'agent']);

        expect($toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId)['scope'])
            ->toBe('agent');
    });

    it('falls back to the principal scope when the tool has no settings service', function (): void {
        // A tool built without a ToolConfigService has no cascade, so the
        // schema default is the only answer it has — and the schema default
        // is `principal`. The asset belongs to the calling agent, so the
        // agent scope would show it; the principal scope does not, because
        // the call carries no principal context to resolve.
        $agentId = scopeDefaultAgent(51505);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99);

        $tool   = buildMediaToolForSchema();
        $result = $tool->execute(['action' => 'get_media', 'asset_id' => $asset->id], agentId: $agentId);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Media asset not found');
    });
});
