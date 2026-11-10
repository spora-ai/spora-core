<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Services\AgentManifest;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\AgentTool;
use Spora\Tools\AgentTool\ConfigurePlanParser;
use Spora\Tools\AgentTool\SkillCatalogPresenter;
use Spora\Tools\SkillTool;
use Spora\Tools\SubAgentTool;
use Spora\Tools\ValueObjects\ToolResult;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * The provider's `{item: …}` envelope, and the `configure_tools` writes it
 * used to make impossible.
 *
 * Every array-valued setting write was refused with "must be an array of
 * strings" although a list had been sent, which left `allowed_skills`
 * ungrantable and `SubAgentTool` permanently unready. These cases pin the
 * shapes that actually arrive: the list inside the envelope, the one-element
 * list with its element unwrapped too, and the envelope around the whole
 * settings map.
 */
defined('ENVELOPE_TEST_PASSWORD') || define('ENVELOPE_TEST_PASSWORD', 'Password1!');

/**
 * A registry whose skills are visible to every principal, so the
 * `allowed_skills` write passes the visibility check.
 */
function envelopeSkillRegistry(): SkillProviderRegistry
{
    return new SkillProviderRegistry([
        (new StubSkillProvider('core'))
            ->add('time-arithmetic', ['SKILL.md'], 'Body.', 'Do sums of times.')
            ->add('email', ['SKILL.md'], 'Body.', 'Read email.'),
    ]);
}

/**
 * The `settings` of the first parsed plan step, or the refusal.
 *
 * @param  array<string, mixed> $settings
 * @return array<string, mixed>|ToolResult
 */
function envelopePlanSettings(array $settings, string $toolClass = SkillTool::class): array|ToolResult
{
    $plan = (new ConfigurePlanParser(envelopeSkillRegistry()))->buildPlan([
        ['tool_class' => $toolClass, 'settings' => $settings],
    ]);
    if ($plan instanceof ToolResult) {
        return $plan;
    }

    return $plan[0]['settings'];
}

/**
 * An `AgentTool` over the real settings service and the real database, so a
 * write can be read back the way production reads it.
 *
 * @return array{0: AgentTool, 1: AgentService, 2: int}
 */
function makeEnvelopeAgentTool(): array
{
    $key        = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $toolConfig = new ToolConfigService(new SecurityManager($key), new Psr\Log\NullLogger(), [SkillTool::class]);
    $llmConfig  = new LLMConfigService(new SecurityManager($key), []);
    $settings   = new AgentToolSettingsService($toolConfig, $llmConfig);
    $agents     = new AgentService();
    $manifest   = new AgentManifest($settings, null);
    $skills     = envelopeSkillRegistry();

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "envelope-{$seq}@example.com", ENVELOPE_TEST_PASSWORD);

    return [
        new AgentTool($agents, $settings, $manifest, new AgentTool\AgentToolCollaborators(
            skills: $skills,
            skillCatalog: new SkillCatalogPresenter($skills, $toolConfig),
        )),
        $agents,
        $userId,
    ];
}

/**
 * The `PrincipalContext` a real orchestrator call for a user-owned agent
 * would carry.
 */
function envelopeContext(int $userId): PrincipalContext
{
    return new PrincipalContext(
        principalId: createUserPrincipalPublic($userId),
        type: Spora\Models\Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );
}

describe('configure_tools — a settings value in the provider envelope', function (): void {

    it('unwraps an enveloped multi-select into the list it stands for', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['item' => ['time-arithmetic', 'email']]]);

        expect($out)->toBe(['allowed_skills' => '["time-arithmetic","email"]']);
    });

    it('unwraps the one-element form, where the element is unwrapped too', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['item' => 'time-arithmetic']]);

        expect($out)->toBe(['allowed_skills' => '["time-arithmetic"]']);
    });

    it('unwraps an agent-id multi-select the same way', function (): void {
        $out = envelopePlanSettings(
            ['allowed_target_agents' => ['item' => [3, 4]]],
            SubAgentTool::class,
        );

        expect($out)->toBe(['allowed_target_agents' => '[3,4]']);
    });

    it('unwraps the envelope around the whole settings map', function (): void {
        $out = envelopePlanSettings(['item' => ['allowed_skills' => ['item' => ['email']]]]);

        expect($out)->toBe(['allowed_skills' => '["email"]']);
    });

    it('leaves a plain list alone', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['time-arithmetic']]);

        expect($out)->toBe(['allowed_skills' => '["time-arithmetic"]']);
    });

    it('still refuses a value that is neither a list nor the envelope', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => 'time-arithmetic']);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->success)->toBeFalse()
            ->and($out->content)->toContain("'allowed_skills' must be an array of strings")
            ->and($out->content)->toContain('string("time-arithmetic")');
    });

    it('names the entry that is the wrong type, not just the list', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['time-arithmetic', ['name' => 'x']]]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->content)->toContain('entry 1 is array, not a string');
    });

    it('names the offending entry inside an envelope too', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['item' => ['email', ['name' => 'x']]]]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->content)->toContain('entry 1 is array, not a string');
    });

    it('names the keys of an object that is not the envelope', function (): void {
        $out = envelopePlanSettings(['allowed_skills' => ['a' => 1, 'b' => 2]]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->content)->toContain('an object with the key(s) a, b');
    });
});

describe('configure_tools — the provider envelope in an operations list', function (): void {

    it('unwraps a one-element operations list whose element is unwrapped too', function (): void {
        $plan = (new ConfigurePlanParser())->buildPlan([
            ['tool_class' => Spora\Tools\TimeTool::class, 'operations' => ['item' => ['name' => 'now']]],
        ]);

        expect($plan)->toBeArray()->and($plan[0]['operations'])->toBe([
            ['name' => 'now', 'enabled' => true, 'auto_approve' => false],
        ]);
    });

    it('unwraps an operations list with the list inside the envelope', function (): void {
        $plan = (new ConfigurePlanParser())->buildPlan([
            [
                'tool_class' => Spora\Tools\TimeTool::class,
                'operations'  => ['item' => [['name' => 'now'], ['name' => 'format']]],
            ],
        ]);

        expect($plan)->toBeArray()->and($plan[0]['operations'])->toHaveCount(2);
    });
});

describe('configure_tools — an enveloped grant lands on the agent', function (): void {

    it('writes the allowlist and reads it back through get_available_tools', function (): void {
        // The regression the report is about: the grant is refused at the
        // parser, so `skills.allowed` stays empty and no skill is loadable.
        [$tool, $agents, $userId] = makeEnvelopeAgentTool();
        $target = $agents->createAgent($userId, ['name' => 'Envelope Target']);

        $write = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [[
                'tool_class' => SkillTool::class,
                'settings'   => ['allowed_skills' => ['item' => ['time-arithmetic', 'email']]],
            ]],
        ], $target->id, context: envelopeContext($userId));

        expect($write->success)->toBeTrue();

        $catalog = $tool->execute(
            ['action' => 'get_available_tools'],
            $target->id,
            context: envelopeContext($userId),
        );

        $payload = json_decode($catalog->content, true);

        expect($catalog->success)->toBeTrue()
            ->and($payload['skills']['allowed'])->toContain('time-arithmetic', 'email');
    });

    it('refuses an enveloped write for a skill the principal cannot see', function (): void {
        // The unwrap must not become a way around the visibility check: the
        // name is still checked against the principal's visible set.
        [$tool, $agents, $userId] = makeEnvelopeAgentTool();
        $target = $agents->createAgent($userId, ['name' => 'Envelope Invisible']);

        $write = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [[
                'tool_class' => SkillTool::class,
                'settings'   => ['allowed_skills' => ['item' => ['not-a-skill']]],
            ]],
        ], $target->id, context: envelopeContext($userId));

        expect($write->success)->toBeFalse()
            ->and($write->content)->toContain("'allowed_skills' names 'not-a-skill'")
            ->and($write->content)->toContain('not available to this principal');
    });
});

describe('ask_user_question — the provider envelope', function (): void {

    it('unwraps an enveloped options list', function (): void {
        $tool = new Spora\Tools\AskUserQuestionTool();

        $result = $tool->execute([
            'questions' => [[
                'question' => 'Does this work?',
                'header'   => 'Check',
                'options'  => ['item' => [
                    ['label' => 'Yes', 'description' => 'It works.'],
                    ['label' => 'No', 'description' => 'It does not.'],
                ]],
            ]],
        ], 7, 1);

        expect($result->success)->toBeTrue();
    });

    it('unwraps an enveloped questions list', function (): void {
        $tool = new Spora\Tools\AskUserQuestionTool();

        $result = $tool->execute([
            'questions' => ['item' => [[
                'question' => 'Does this work?',
                'header'   => 'Check',
                'options'  => [
                    ['label' => 'Yes', 'description' => 'It works.'],
                    ['label' => 'No', 'description' => 'It does not.'],
                ],
            ]]],
        ], 7, 1);

        expect($result->success)->toBeTrue();
    });
});
