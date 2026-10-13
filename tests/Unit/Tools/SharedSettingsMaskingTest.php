<?php

declare(strict_types=1);

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Tools\SharedSettingsResolver;
use Tests\Unit\Tools\AcmeChatTool;
use Tests\Unit\Tools\AcmeMistypedTool;
use Tests\Unit\Tools\AcmeSharedSettings;
use Tests\Unit\Tools\AcmeStandaloneTool;

/**
 * `GET|PUT /api/v1/agents/{id}/tools/{toolId}/override` carries only
 * `AuthMiddleware` — it is not an admin surface. So anything the shared
 * settings cascade puts into `getEffectiveSettingsWithSource()` on that route
 * has to be masked, and it has to be masked against the schema that encrypted
 * it.
 *
 * It did not. `getEffectiveSettingsWithSource()` began returning shared-group
 * values, while this path masked with `getToolPasswordKeys($toolClass)` — raw
 * reflection over the *tool's* attributes. A tool that declares the shared
 * key as `text` (a mistyping no schema check can catch) therefore published
 * the plugin-wide credential in clear to anyone who could see the agent.
 */
beforeEach(function (): void {
    TestDatabaseFactory::boot();
});

/**
 * @return array{0: ToolConfigService, 1: AgentToolOverrideResolver}
 */
function sharedLeakFixture(): array
{
    $security = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $classes  = [AcmeChatTool::class, AcmeStandaloneTool::class, AcmeMistypedTool::class];

    $toolConfig = new ToolConfigService(
        $security,
        new Logger('test'),
        $classes,
        new ToolConfigSchemaInspector(),
        new PrincipalService(new PrincipalResolver()),
        false,
        new PrincipalResolver(),
        new SharedSettingsResolver([AcmeSharedSettings::class]),
    );

    $llmConfig = new LLMConfigService($security, []);

    return [
        $toolConfig,
        new AgentToolOverrideResolver($toolConfig, $llmConfig, new LLMConfigPreferences()),
    ];
}

it('masks a shared secret on the agent-override route even when the tool mistypes it as text', function (): void {
    [$toolConfig, $resolver] = sharedLeakFixture();

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => 'SHARED-PLAINTEXT-CANARY']);

    // A real agent row: getOverride() returns [] for a caller who cannot see
    // one, so a fixture with agentId 0 proves nothing — it passes against the
    // leak as readily as against the fix.
    $userId  = bootAuth(bootAuthLayer(), 'shared-leak@example.com', 'Password1!', 'Leak Check');
    $agentId = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Shared leak',
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;

    $body = (string) json_encode($resolver->getOverride($agentId, $userId, AcmeMistypedTool::class, false));

    expect($body)->not->toContain('SHARED-PLAINTEXT-CANARY')
        ->and($body)->toContain('***');
});

it('includes the group password keys in the maskable set', function (): void {
    [$toolConfig] = sharedLeakFixture();

    // The mistyping tool declares no password of its own — its own list is
    // empty, which is why the tool-only mask leaked.
    expect($toolConfig->maskablePasswordKeys(AcmeMistypedTool::class))->toContain('api_key');
});

it('masks a shared secret through maskForApi for the same tool', function (): void {
    [$toolConfig] = sharedLeakFixture();

    $masked = $toolConfig->maskForApi(['api_key' => 'SHARED-PLAINTEXT-CANARY'], AcmeMistypedTool::class);

    expect($masked['api_key'])->toBe('***');
});

it('leaves a tool with no group masking only its own declared passwords', function (): void {
    [$toolConfig] = sharedLeakFixture();

    expect($toolConfig->maskablePasswordKeys(AcmeStandaloneTool::class))
        ->toBe($toolConfig->maskablePasswordKeys(AcmeStandaloneTool::class))
        ->and(array_diff($toolConfig->maskablePasswordKeys(AcmeStandaloneTool::class), ['api_key']))->toBe([]);
});
