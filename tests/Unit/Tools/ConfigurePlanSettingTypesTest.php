<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\AgentManifest;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\AgentTool;
use Spora\Tools\AgentTool\ConfigurePlanParser;
use Spora\Tools\MediaTool;
use Spora\Tools\ValueObjects\ToolResult;

defined('AGENT_SETTING_TYPES_PASSWORD') || define('AGENT_SETTING_TYPES_PASSWORD', 'Password1!');

/**
 * `configure_tools` settings whose declared type is a *bound*, not a free value.
 *
 * `ConfigurePlanParser::coerceSetting()` matched only `type: 'password'`
 * (refuse) and `type: 'multi-select'` (coerce). Every other declared type fell
 * through to `default => $value` and was written verbatim — which left two
 * real declarations unchecked:
 *
 *   - `MediaTool::scope`, `type: 'select'`, options `agent` / `principal`.
 *     `configure_tools(tools: [{tool_class: MediaTool, settings: {scope: "admin"}}])`
 *     persisted.
 *   - `AbstractCompatibleDriver::supports_image_input`, `type: 'toggle'`.
 *     `settings: {supports_image_input: "false"}` wrote the literal string
 *     `"false"` into a boolean column, which reads back as true — the exact
 *     defect `AgentPatchValidator` was added in this release to eliminate, and
 *     the exact thing `skills/agent-tool/SKILL.md` promises cannot happen
 *     ("A quoted value is read as the value it names, never as truthiness").
 *
 * The plan is the value the write carries, so asserting on it is asserting on
 * the write; the end-to-end cases additionally decrypt the stored row.
 *
 * @return array{0: AgentTool, 1: AgentService, 2: AgentToolSettingsService, 3: int}
 */
function makeAgentToolForSettingTypes(): array
{
    $key        = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $toolConfig = new ToolConfigService(new SecurityManager($key), new NullLogger(), [MediaTool::class]);
    $llmConfig  = new LLMConfigService(new SecurityManager($key), []);
    $settings   = new AgentToolSettingsService($toolConfig, $llmConfig);
    $agents     = new AgentService();
    $manifest   = new AgentManifest($settings, null);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "agent-setting-types-{$seq}@example.com", AGENT_SETTING_TYPES_PASSWORD);

    return [new AgentTool($agents, $settings, $manifest), $agents, $settings, $userId];
}

if (!function_exists('agentSettingTypesContext')) {
    function agentSettingTypesContext(int $userId): PrincipalContext
    {
        return new PrincipalContext(
            principalId: createUserPrincipalPublic($userId),
            type: Spora\Models\Principal::TYPE_USER,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );
    }
}

/**
 * The `settings` of the first parsed plan step, or a failure marker.
 *
 * @param  array<int, array<string, mixed>> $entries
 * @return array<string, mixed>|ToolResult
 */
function firstPlanSettings(array $entries): array|ToolResult
{
    $plan = (new ConfigurePlanParser())->buildPlan($entries);
    if ($plan instanceof ToolResult) {
        return $plan;
    }

    return $plan[0]['settings'];
}

describe('configure_tools — a select is checked against its declared options', function (): void {

    it('refuses a value outside the options, naming the legal ones', function (): void {
        $out = firstPlanSettings([
            ['tool_class' => MediaTool::class, 'settings' => ['scope' => 'admin']],
        ]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->success)->toBeFalse()
            ->and($out->content)->toContain("'scope' must be one of: agent, principal")
            ->and($out->content)->toContain('string("admin")');
    });

    it('refuses a non-string for a select rather than coercing it', function (): void {
        // `agent` is a legal value; `1` is not. Casting would silently pick
        // the first option, which is a claim the model never made.
        $out = firstPlanSettings([
            ['tool_class' => MediaTool::class, 'settings' => ['scope' => 1]],
        ]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->content)->toContain("'scope' must be one of: agent, principal");
    });

    it('accepts a declared option verbatim', function (): void {
        foreach (['agent', 'principal'] as $legal) {
            $out = firstPlanSettings([
                ['tool_class' => MediaTool::class, 'settings' => ['scope' => $legal]],
            ]);

            expect($out)->toBe(['scope' => $legal]);
        }
    });

    it('writes nothing at all when the select is refused', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolForSettingTypes();
        $target = $agents->createAgent($userId, ['name' => 'Select Refused']);

        $result = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [[
                'tool_class' => MediaTool::class,
                'enabled'    => true,
                'settings'   => ['scope' => 'admin'],
            ]],
        ], $target->id, context: agentSettingTypesContext($userId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('must be one of: agent, principal')
            ->and(Capsule::table('agent_tool_overrides')->where('agent_id', $target->id)->count())->toBe(0)
            ->and(Capsule::table('agent_tools')->where('agent_id', $target->id)->count())->toBe(0);
    });

    it('lands a legal select value and reads it back off the stored row', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolForSettingTypes();
        $target = $agents->createAgent($userId, ['name' => 'Select Legal']);

        $result = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [[
                'tool_class' => MediaTool::class,
                'settings'   => ['scope' => 'principal'],
            ]],
        ], $target->id, context: agentSettingTypesContext($userId));

        expect($result->success)->toBeTrue();

        $stored = Capsule::table('agent_tool_overrides')
            ->where('agent_id', $target->id)
            ->where('tool_class', MediaTool::class)
            ->value('settings');
        expect(json_decode((string) $stored, true))->toBe(['scope' => 'principal']);
    });
});

describe('configure_tools — a toggle is coerced to a real boolean', function (): void {

    it('reads a quoted "false" as boolean false, never the string', function (): void {
        // The regression. `(bool) "false"` is true, so this used to store the
        // literal string in a boolean column and the flag read back as ON.
        $out = firstPlanSettings([
            ['tool_class' => OpenAICompatibleDriver::class, 'settings' => ['supports_image_input' => 'false']],
        ]);

        expect($out)->toBe(['supports_image_input' => false]);
    });

    it('still coerces the other spellings the shared helper accepts', function (mixed $sent, bool $stored): void {
        expect(firstPlanSettings([
            ['tool_class' => OpenAICompatibleDriver::class, 'settings' => ['supports_image_input' => $sent]],
        ]))->toBe(['supports_image_input' => $stored]);
    })->with([
        'bool true'        => [true, true],
        'bool false'       => [false, false],
        'string "true"'    => ['true', true],
        'string "0"'       => ['0', false],
        'int 1'            => [1, true],
        'int 0'            => [0, false],
        'blank string'     => ['', false],
    ]);

    it('refuses a value that names no flag, rather than storing it', function (): void {
        foreach (['yes', 2, null, []] as $vague) {
            $out = firstPlanSettings([
                ['tool_class' => OpenAICompatibleDriver::class, 'settings' => ['supports_image_input' => $vague]],
            ]);

            expect($out)->toBeInstanceOf(ToolResult::class)
                ->and($out->content)->toContain("'supports_image_input' must be true or false");
        }
    });
});

describe('configure_tools — a non-array operations value is refused, not dropped', function (): void {

    it('refuses a string where an operations list was claimed', function (): void {
        // The silent half: `{"operations": "now"}` returned "no operations",
        // so the whole call reported success having applied nothing, and the
        // model read that as an enablement that landed.
        $out = firstPlanSettings([
            ['tool_class' => Spora\Tools\TimeTool::class, 'operations' => 'now'],
        ]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->content)->toContain('operations[0] must be an array of `{name, enabled?, auto_approve?}`');
    });

    it('refuses an object that is not the single-item unwrap shape, same as settings', function (): void {
        foreach ([3, true, ['a' => 1, 'b' => 2]] as $notAList) {
            $plan = (new ConfigurePlanParser())->buildPlan([
                ['tool_class' => Spora\Tools\TimeTool::class, 'operations' => $notAList],
            ]);

            expect($plan)->toBeInstanceOf(ToolResult::class)
                ->and($plan->content)->toStartWith('configure_tools: operations[0]');
        }
    });

    it('still treats absent, null and empty operations as "inherit the defaults"', function (): void {
        foreach ([[], null, 'omitted'] as $empty) {
            $plan = (new ConfigurePlanParser())->buildPlan(
                $empty === 'omitted'
                    ? [['tool_class' => Spora\Tools\TimeTool::class]]
                    : [['tool_class' => Spora\Tools\TimeTool::class, 'operations' => $empty]],
            );

            expect($plan)->toBeArray()->and($plan[0]['operations'])->toBe([]);
        }
    });

    it('still unwraps the OpenAI {item: [...]} quirk rather than refusing it', function (): void {
        $plan = (new ConfigurePlanParser())->buildPlan([
            ['tool_class' => Spora\Tools\TimeTool::class, 'operations' => ['item' => [['name' => 'now']]]],
        ]);

        expect($plan)->toBeArray()->and($plan[0]['operations'])->toBe([
            ['name' => 'now', 'enabled' => true, 'auto_approve' => false],
        ]);
    });
});
