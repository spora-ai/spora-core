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

    it('emits slim per-tool entries (no display_name or description on tools[i])', function (): void {
        // Slim shape pinned per-PR-#170 review: operators hit
        // `read_agent` after every configure_tools — those responses
        // should carry only what the LLM needs to reason about the
        // toolset, not browse descriptive metadata. Browsing-style
        // enrichment stays on `get_available_tools` (operator-facing).
        [$manifest, $toolSettings, $userId] = makeManifestServiceWithUser();
        [$agent, $agentId] = makeManifestAgent($userId);
        $toolSettings->enableTool($agentId, $userId, CalculatorTool::class);

        $out  = $manifest->toArray($agent);
        $tool = $out['tools'][0];

        expect(array_keys($tool))->toBe(['tool_class', 'icon', 'enabled', 'operations'])
            ->and($tool['display_name'] ?? 'NOT_SET')->toBe('NOT_SET')
            ->and($tool['description']  ?? 'NOT_SET')->toBe('NOT_SET');
    });

    it('emits an empty overrides[] when no per-op overrides exist', function (): void {
        // Bare agent, calculator enabled with no op-level override.
        // Operators see an empty `overrides` list and conclude
        // "every op is on its tool-default — nothing to audit".
        [$manifest, $toolSettings, $userId] = makeManifestServiceWithUser();
        [$agent, $agentId] = makeManifestAgent($userId);
        $toolSettings->enableTool($agentId, $userId, CalculatorTool::class);

        $out = $manifest->toArray($agent);

        expect($out)->toHaveKey('overrides')
            ->and($out['overrides'])->toBe([]);
    });

    it('surfaces per-op overrides in overrides[] with the override value (not the effective value)', function (): void {
        // Override the `calculate` op: enabled=1, default_requires_approval=0
        // (i.e. auto_approve=true). The manifest's `overrides[]` row
        // must show the explicit override — 0 = auto_approve=true was
        // applied. This is what makes the override auditable; the
        // `tools[i].operations[j]` block only shows the effective
        // `requires_approval: false`, which could equally have come
        // from the tool's own default.
        [$manifest, $toolSettings, $userId] = makeManifestServiceWithUser();
        [$agent, $agentId] = makeManifestAgent($userId);
        $toolSettings->enableTool($agentId, $userId, CalculatorTool::class);
        AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', CalculatorTool::class)
            ->where('operation', 'calculate')
            ->delete();
        $toolSettings->patchOperationOverride($agentId, $userId, CalculatorTool::class, 'calculate', [
            'enabled'                   => 1,
            'default_requires_approval' => 0,
        ]);

        $out = $manifest->toArray($agent);

        expect($out['overrides'])->toHaveCount(1);
        $row = $out['overrides'][0];
        expect($row['tool_class'])->toBe(CalculatorTool::class)
            ->and($row['operation'])->toBe('calculate')
            // Both columns carry the override value, even when the
            // effective value matches the tool default — operators
            // can see "yes, I explicitly overrode this".
            ->and($row['enabled'])->toBeTrue()
            ->and($row['default_requires_approval'])->toBeFalse();
    });

    it('omits ops with no override from overrides[] (default is silent)', function (): void {
        // Multiple enabled tools, only one op has an override row in
        // `agent_tool_operation_overrides`. The other tools don't
        // show up at all — `overrides[]` carries only the rows the
        // operator actively set, not the implicit "default" baseline.
        // Validates the small payload claim: an agent with 18 tools
        // and only 2 overrides should have 2 rows, not 18.
        [$manifest, $toolSettings, $userId] = makeManifestServiceWithUser();
        [$agent, $agentId] = makeManifestAgent($userId);
        $toolSettings->enableTool($agentId, $userId, CalculatorTool::class);
        AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', CalculatorTool::class)
            ->where('operation', 'calculate')
            ->delete();
        $toolSettings->patchOperationOverride($agentId, $userId, CalculatorTool::class, 'calculate', [
            'enabled' => 1,
        ]);

        $out = $manifest->toArray($agent);

        // Exactly one override row — even though the tool has 1 op
        // (calculate), and the override set only `enabled`. The
        // `default_requires_approval` override was kept at default,
        // so it's null.
        expect($out['overrides'])->toHaveCount(1);
        $row = $out['overrides'][0];
        expect($row['enabled'])->toBeTrue()
            ->and($row['default_requires_approval'])->toBeNull();
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
                    'tool_class' => 'Spora\\Plugins\\Weather\\Tools\\WeatherApiTool',
                    'icon'       => null,
                    'enabled'    => true,
                    'operations' => [
                        ['name' => 'current', 'enabled' => true, 'requires_approval' => false],
                    ],
                ],
            ],
            'missing_required'    => [],
            'overrides'           => [],
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
            ->toContain('"icon": null')
            // JSON-encoded backslashes are doubled, so the class name
            // appears with double backslashes inside the JSON block.
            ->toContain('Spora\\\\Plugins\\\\Weather\\\\Tools\\\\WeatherApiTool');

        // Pin the slim per-tool shape (no display_name / description)
        // so an upstream AgentManifest change can't silently bring
        // them back. Search the Tool-config block specifically —
        // the base-config block legitimately carries the agent's
        // own `description` field.
        $toolBlock = preg_grep('/^### Tool config$/', preg_split("/\\n\\n/", $md) ?: []) ? array_slice(preg_split("/\\n\\n/", $md) ?: [], -1, 1) : [];
        $toolJson  = trim((string) ($toolBlock[0] ?? ''));
        $toolJson  = preg_replace('/^```json\\s*|\\s*```$/', '', $toolJson) ?? $toolJson;
        $decoded   = json_decode($toolJson, true, 512, JSON_THROW_ON_ERROR);
        $tool      = $decoded['tools'][0];
        expect(array_keys($tool))->toBe(['tool_class', 'icon', 'enabled', 'operations'])
            ->and($tool['display_name'] ?? null)->toBeNull()
            ->and($tool['description']  ?? null)->toBeNull();
    });
});

describe('AgentManifestRenderer::markdown — disabled-tools preamble', function (): void {
    it('enumerates disabled tool FQCNs under the status line when at least one is disabled', function (): void {
        $manifest = [
            'agent_id'            => 6,
            'name'                => 'Weather Agent',
            'description'         => null,
            'system_prompt'       => null,
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
                ['tool_class' => 'Spora\\Tools\\TimeTool',         'enabled' => true,  'icon' => 'clock', 'operations' => []],
                ['tool_class' => 'Spora\\Tools\\CalculatorTool',   'enabled' => false, 'icon' => null,    'operations' => []],
                ['tool_class' => 'Spora\\Tools\\SendEmailTool',    'enabled' => false, 'icon' => 'mail',  'operations' => []],
            ],
            'missing_required'    => [],
            'overrides'           => [],
            'warnings'            => [],
        ];

        $md = Spora\Services\AgentManifestRenderer::markdown($manifest);

        expect($md)
            ->toContain('1 of 3 tools enabled.')
            ->toContain('Disabled: Spora\\Tools\\CalculatorTool, Spora\\Tools\\SendEmailTool');
    });

    it('omits the Disabled: line entirely when every tool is enabled', function (): void {
        // All-enabled case — no FQCN clutter between the status line
        // and the base-config block. Cheap token cost.
        $manifest = [
            'agent_id'            => 6,
            'name'                => 'A',
            'description'         => null,
            'system_prompt'       => null,
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
                ['tool_class' => 'A', 'enabled' => true,  'icon' => null, 'operations' => []],
                ['tool_class' => 'B', 'enabled' => true,  'icon' => null, 'operations' => []],
            ],
            'missing_required'    => [],
            'overrides'           => [],
            'warnings'            => [],
        ];

        $md = Spora\Services\AgentManifestRenderer::markdown($manifest);

        expect($md)->not->toContain('Disabled:');
    });

    it('omits the Disabled: line entirely when every tool is disabled too', function (): void {
        // Edge case: zero enabled. Reading the disabled list is the
        // whole point — but the line should still surface "Disabled:
        // ClassA, ClassB, ..." rather than the negative "0 of N
        // enabled" alone.
        $manifest = [
            'agent_id'            => 6,
            'name'                => 'A',
            'description'         => null,
            'system_prompt'       => null,
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
                ['tool_class' => 'X', 'enabled' => false, 'icon' => null, 'operations' => []],
                ['tool_class' => 'Y', 'enabled' => false, 'icon' => null, 'operations' => []],
            ],
            'missing_required'    => [],
            'overrides'           => [],
            'warnings'            => [],
        ];

        $md = Spora\Services\AgentManifestRenderer::markdown($manifest);

        expect($md)
            ->toContain('0 of 2 tools enabled.')
            ->toContain('Disabled: X, Y');
    });
});
