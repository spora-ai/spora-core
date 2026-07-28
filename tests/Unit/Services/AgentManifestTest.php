<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Services\AgentManifest;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;

defined('AGENT_TEST_PASSWORD') || define('AGENT_TEST_PASSWORD', 'Password1!');

/**
 * @return array{0: AgentManifest, 1: AgentToolSettingsService, 2: int}
 */
function makeManifestServiceWithUser(): array
{
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new NullLogger();
    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, []);
    $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
    $manifest = new AgentManifest($toolSettings, null);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "manifest-{$seq}@example.com", AGENT_TEST_PASSWORD);
    return [$manifest, $toolSettings, $userId];
}

/**
 * @return array{0: Agent, 1: int}
 */
function makeManifestAgent(int $userId): array
{
    $id = Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
        'user_id'                => $userId,
        'name'                   => 'Manifest Test',
        'description'            => 'desc',
        'system_prompt'          => 'sp',
        'max_steps'              => 10,
        'is_active'              => 1,
        'allow_followup'         => 1,
        'retry_after_minutes'    => 0,
        'max_retries'            => 0,
        'is_pinned'              => 0,
        'is_archived'            => 0,
        'is_favorite'            => 0,
        'created_at'             => date('Y-m-d H:i:s'),
        'updated_at'             => date('Y-m-d H:i:s'),
    ]);
    return [Agent::find($id), $id];
}

describe('AgentManifest::toArray', function (): void {

    it('emits the canonical shape with empty tools for a bare agent', function (): void {
        [$manifest, , $userId] = makeManifestServiceWithUser();
        [$agent, ] = makeManifestAgent($userId);

        $out = $manifest->toArray($agent);

        expect($out['agent_id'])->toBe((int) $agent->id)
            ->and($out['name'])->toBe('Manifest Test')
            ->and($out['description'])->toBe('desc')
            ->and($out['system_prompt'])->toBe('sp')
            ->and($out['template_id'])->toBeNull()
            ->and($out['version'])->toBeNull()
            ->and($out['max_steps'])->toBe(10)
            ->and($out['allow_followup'])->toBeTrue()
            ->and($out['retry_after_minutes'])->toBe(0)
            ->and($out['max_retries'])->toBe(0)
            ->and($out['is_pinned'])->toBeFalse()
            ->and($out['is_archived'])->toBeFalse()
            ->and($out['is_favorite'])->toBeFalse()
            // Calculator is registered in the test's ToolConfigService
            // but not enabled on this bare agent — manifest still
            // surfaces it with enabled=false so the LLM can plan.
            ->and($out['tools'])->toHaveCount(1)
            ->and($out['tools'][0]['tool_class'])->toBe(CalculatorTool::class)
            ->and($out['tools'][0]['enabled'])->toBeFalse()
            ->and($out['missing_required'])->toBe([])
            ->and($out['warnings'])->toBe([]);
    });

    it('emits per-tool per-operation state using effective overrides', function (): void {
        [$manifest, $toolSettings, $userId] = makeManifestServiceWithUser();
        [$agent, $agentId] = makeManifestAgent($userId);

        $toolSettings->enableTool($agentId, $userId, CalculatorTool::class);

        // Override the `calculate` operation: enabled=1, default_requires_approval=0
        // (i.e. auto-approve). This flips the effective state from the default.
        AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', CalculatorTool::class)
            ->where('operation', 'calculate')
            ->delete();
        $toolSettings->patchOperationOverride($agentId, $userId, CalculatorTool::class, 'calculate', [
            'enabled'                   => 1,
            'default_requires_approval' => 0,
        ]);

        $out = $manifest->toArray($agent);

        expect($out['tools'])->toHaveCount(1);
        $calc = $out['tools'][0];
        expect($calc['tool_class'])->toBe(CalculatorTool::class)
            ->and($calc['enabled'])->toBeTrue()
            ->and($calc['operations'])->toHaveCount(1);
        $op = $calc['operations'][0];
        expect($op['name'])->toBe('calculate')
            ->and($op['enabled'])->toBeTrue()
            // Overrode to auto-approve:
            ->and($op['requires_approval'])->toBeFalse();
    });

    it('surfaces missing_required for enabled tools with required config absent', function (): void {
        [$manifest, , $userId] = makeManifestServiceWithUser();
        [$agent, ] = makeManifestAgent($userId);

        // CalculatorTool has no required settings so this is just a smoke
        // test for the empty path. The non-empty path is exercised by
        // agents that depend on a plugin tool like WeatherApiTool in
        // integration tests; here we check the field exists and is a list.
        $out = $manifest->toArray($agent);
        expect($out['missing_required'])->toBe([]);
    });
});

describe('AgentManifestRenderer::markdown', function (): void {

    it('renders two JSON code blocks with the agent preamble', function (): void {
        $manifest = [
            'agent_id'            => 6,
            'name'                => 'Weather Agent',
            'description'         => 'Answers weather questions.',
            'system_prompt'       => 'You are the Weather Agent.',
            'template_id'         => null,
            'version'             => null,
            'max_steps'           => 10,
            'allow_followup'      => true,
            'retry_after_minutes' => 0,
            'max_retries'         => 0,
            'is_pinned'           => false,
            'is_archived'         => false,
            'is_favorite'         => false,
            'tools'               => [
                [
                    'tool_class'   => 'Spora\\Plugins\\Weather\\Tools\\WeatherApiTool',
                    'display_name' => 'Weather API',
                    'description'  => 'Fetch weather data.',
                    'icon'         => null,
                    'enabled'      => true,
                    'operations'   => [
                        ['name' => 'current', 'enabled' => true, 'requires_approval' => false],
                    ],
                ],
            ],
            'missing_required'    => [],
            'warnings'            => [],
        ];

        $md = Spora\Services\AgentManifestRenderer::markdown($manifest);

        expect($md)
            ->toContain("## Agent #6 \u{2014} Weather Agent")
            ->toContain('1 of 1 tools enabled.')
            ->toContain('0 missing required config.')
            ->toContain('### Base config')
            ->toContain('### Tool config')
            ->toContain('"agent_id": 6')
            ->toContain('"display_name": "Weather API"')
            // JSON-encoded backslashes are doubled, so the class name
            // appears with double backslashes inside the JSON block.
            ->toContain('Spora\\\\Plugins\\\\Weather\\\\Tools\\\\WeatherApiTool');
    });
});
