<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Spora\Tools\SharedSettingsResolver;

/**
 * The shared-group layer is a *fallback*, not an override. These tests pin the
 * whole ordering, because every one of the six orders looks correct in a
 * single-tool snapshot and only the ones below distinguish them:
 *
 *   schema defaults < shared group < tool global < principal < agent override
 */

function sharedToolConfig(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Logger('test'),
        [AcmeChatTool::class, AcmeStandaloneTool::class],
        sharedSettings: new SharedSettingsResolver([AcmeSharedSettings::class]),
    );
}

function sharedAgentFor(string $email): int
{
    $userId = bootAuthLayer()->register($email, 'Password1!', 'Shared Settings');

    $agent = new Agent();
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->name = 'Shared settings agent';
    $agent->save();

    return (int) $agent->id;
}

it('leaves a tool that names no group completely unaffected', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('no-group@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);

    expect($service->getEffectiveSettings(AcmeStandaloneTool::class, $agentId))
        ->not->toHaveKey('api_key')
        ->and($service->getEffectiveSettings(AcmeStandaloneTool::class, $agentId)['retries'])->toBe('3');
});

it('falls back to the shared value when the tool declares none', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('fallback@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, [
        'api_key' => 'shared-secret',
        'region'   => 'eu-west',
    ]);

    $effective = $service->getEffectiveSettings(AcmeChatTool::class, $agentId);

    expect($effective['api_key'])->toBe('shared-secret')
        ->and($effective['region'])->toBe('eu-west');
});

it('lets the tool own value win over the shared one', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('tool-wins@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);
    $service->putGlobalSettings(AcmeChatTool::class, ['api_key' => 'tool-secret']);

    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId)['api_key'])->toBe('tool-secret');
});

it('lets a principal value win over the shared one', function (): void {
    $service = sharedToolConfig();
    $userId  = bootAuthLayer()->register('principal-wins@example.com', 'Password1!', 'Principal Wins');
    $principalId = createUserPrincipalPublic($userId);

    $agent = new Agent();
    $agent->principal_id = $principalId;
    $agent->name = 'Shared settings agent';
    $agent->save();
    $agentId = (int) $agent->id;

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);
    $service->putPrincipalSettings(AcmeChatTool::class, $principalId, ['api_key' => 'principal-secret']);

    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId, $userId)['api_key'])->toBe('principal-secret');
});

it('lets an agent override win over the shared and the tool value', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('agent-wins@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);
    $service->putGlobalSettings(AcmeChatTool::class, ['api_key' => 'tool-secret']);
    $service->putAgentOverride(AcmeChatTool::class, $agentId, ['api_key' => 'agent-secret']);

    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId)['api_key'])->toBe('agent-secret');
});

it('keeps the schema default when the group has no value for the key', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('default-kept@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);

    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId)['retries'])->toBe('3');
});

it('lets the group outrank the schema default, which is the lowest level', function (): void {
    // A code default is a placeholder for "nothing entered yet"; a value in
    // the group is something the operator typed. The locked order puts the
    // group above the default, so the group wins — and this assertion fails
    // against an overlay that only fills keys the default left absent.
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('group-beats-default@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['retries' => '99']);

    expect($service->getEffectiveSettings(AcmeChatTool::class, $agentId)['retries'])->toBe('99');
});

it('falls through an explicitly cleared value to the shared one', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('cleared-falls-through@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);
    // What the form sends when an operator empties the field to inherit.
    $service->putGlobalSettings(AcmeChatTool::class, ['api_key' => '']);

    expect($service->getGlobalSettings(AcmeChatTool::class)['api_key'])->toBe('')
        ->and($service->getEffectiveSettings(AcmeChatTool::class, $agentId)['api_key'])->toBe('shared-secret');
});

it('keeps the empty string when there is nothing below to inherit', function (): void {
    // An ungrouped tool with a cleared key has no shared value and no default,
    // so '' must survive exactly as it did before this layer existed —
    // getMissingRequiredSettings() reads '' and null the same way, but the
    // effective array is a public contract.
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('cleared-stays-empty@example.com');

    $service->putGlobalSettings(AcmeStandaloneTool::class, ['api_key' => '']);

    expect($service->getEffectiveSettings(AcmeStandaloneTool::class, $agentId))->toHaveKey('api_key')
        ->and($service->getEffectiveSettings(AcmeStandaloneTool::class, $agentId)['api_key'])->toBe('');
});

it('reports the shared source for a value only the group supplies', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('source-shared@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);

    $annotated = $service->getEffectiveSettingsWithSource(AcmeChatTool::class, $agentId);

    expect($annotated['api_key'])->toBe(['value' => 'shared-secret', 'source' => 'shared'])
        ->and($annotated['retries']['source'])->toBe('default');
});

it('reports the tool source when the tool owns the value', function (): void {
    $service = sharedToolConfig();
    $agentId = sharedAgentFor('source-global@example.com');

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);
    $service->putGlobalSettings(AcmeChatTool::class, ['api_key' => 'tool-secret']);

    $annotated = $service->getEffectiveSettingsWithSource(AcmeChatTool::class, $agentId);

    expect($annotated['api_key']['source'])->toBe('global');
});

it('encrypts the shared credential at rest like any other password setting', function (): void {
    $service = sharedToolConfig();

    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'shared-secret']);

    $stored = \Spora\Models\ToolConfiguration::where('tool_class', AcmeSharedSettings::class)->first();

    expect($stored)->not->toBeNull()
        ->and($stored->getRawOriginal('settings'))->not->toContain('shared-secret')
        ->and($service->getGlobalSettings(AcmeSharedSettings::class)['api_key'])->toBe('shared-secret');
});

it('labels a group value that overrode a code default as shared', function (): void {
    // `applyToDefaults()` writes the group's value into the same array it was
    // given, so testing the merged result against the schema defaults could
    // not tell them apart: `retries` came back as `"99"` from the group and
    // was annotated `default`, contradicting the method's own contract.
    $service = sharedToolConfig();
    $service->putGlobalSettings(AcmeSharedSettings::class, ['retries' => '99']);

    $annotated = $service->getEffectiveSettingsWithSource(AcmeChatTool::class, 0);

    expect($annotated['retries']['value'])->toBe('99')
        ->and($annotated['retries']['source'])->toBe('shared');
});

it('still labels a key only the schema declares as default', function (): void {
    $service = sharedToolConfig();
    $service->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'from-group']);

    $annotated = $service->getEffectiveSettingsWithSource(AcmeChatTool::class, 0);

    expect($annotated['api_key']['source'])->toBe('shared')
        ->and($annotated['region']['source'] ?? 'default')->toBe('default');
});
