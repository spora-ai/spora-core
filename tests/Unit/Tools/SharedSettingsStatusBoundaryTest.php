<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\SharedSettingsResolver;

/**
 * `ToolStatus` is derived from the settings cascade, so anything merged into
 * `getEffectiveSettings()` reaches `missing_required` by construction. The
 * shared-group layer is deliberately excluded from that derivation, and this
 * file is the tripwire for that decision.
 *
 * The question is genuinely open — the shared credential is real, so a tool
 * that *runs* on it arguably *is* configured. It is deferred rather than
 * decided here because the answer changes what the operator sees on install
 * (five tools silently becoming `can_enable: true`), which is a product call.
 * If it is ever reversed, {@see AgentToolSettingsService::STATUS_INCLUDES_SHARED_SETTINGS}
 * flips and these assertions invert. They should not be edited to make either
 * outcome pass quietly.
 */

function statusToolConfig(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Logger('test'),
        [AcmeChatTool::class, AcmeStandaloneTool::class],
        sharedSettings: new SharedSettingsResolver([AcmeSharedSettings::class]),
    );
}

function statusSettingsService(ToolConfigService $service): AgentToolSettingsService
{
    return new AgentToolSettingsService(
        $service,
        new LLMConfigService(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
    );
}

function statusAgentWithTool(ToolConfigService $service, string $email): array
{
    $userId = bootAuthLayer()->register($email, 'Password1!', 'Status Shape');

    $agent = new Agent();
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->name = 'Status shape agent';
    $agent->save();
    $agentId = (int) $agent->id;

    AgentTool::where('agent_id', $agentId)->where('tool_class', AcmeChatTool::class)->delete();
    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);

    return [$agentId, $userId];
}

it('still reports the shared credential as missing_required after the group supplies it', function (): void {
    $service = statusToolConfig();
    [$agentId, $userId] = statusAgentWithTool($service, 'status-missing@example.com');
    AgentTool::insert([
        'agent_id'   => $agentId,
        'tool_class' => AcmeChatTool::class,
        'tool_name'  => 'acme_chat',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $settingsService = statusSettingsService($service);

    $status = $settingsService->getToolStatus($agentId, $userId, AcmeChatTool::class);

    expect($status['missing_required'])->toBe(['api_key'])
        ->and($status['can_enable'])->toBeFalse();
});

it('resolves the credential for the tool itself while the status withholds it', function (): void {
    $service = statusToolConfig();
    [$agentId, $userId] = statusAgentWithTool($service, 'status-split@example.com');

    // The runtime path sees the shared value...
    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId, $userId)['api_key'])
        ->toBe('shared-secret');

    // ...while the same resolution asked to leave the shared layer out does
    // not. This is the whole boundary in one test: one method, two answers,
    // decided by the flag rather than by which caller happens to be there.
    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId, $userId, null, includeSharedSettings: false))
        ->not->toHaveKey('api_key');
});

it('keeps the status identical to a tool that never joined a group', function (): void {
    $service = statusToolConfig();
    [$agentId, $userId] = statusAgentWithTool($service, 'status-parity@example.com');

    $settingsService = statusSettingsService($service);

    $grouped    = $settingsService->getToolStatus($agentId, $userId, AcmeChatTool::class);
    $ungrouped  = $settingsService->getToolStatus($agentId, $userId, AcmeStandaloneTool::class);

    expect($grouped['missing_required'])->toBe($ungrouped['missing_required']);
});

it('does not let the group suppress the enable-time warning', function (): void {
    $service = statusToolConfig();
    [$agentId, $userId] = statusAgentWithTool($service, 'status-enable@example.com');

    $settingsService = statusSettingsService($service);

    $result = $settingsService->enableTool($agentId, $userId, AcmeChatTool::class);

    expect($result)->toHaveKey('warning')
        ->and($result['missing_required'])->toBe(['api_key']);
});
