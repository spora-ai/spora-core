<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Services\AgentManifest;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\AgentTool;

defined('AGENT_PROMPT_PATCH_PASSWORD') || define('AGENT_PROMPT_PATCH_PASSWORD', 'Password1!');

/**
 * `system_prompt` must be symmetric between `create_agent` and `update_agent`.
 *
 * `AgentPatchValidator::PATCHABLE` routed `system_prompt` through `T_TEXT`,
 * which lands on `nullableString()` and therefore on `DESC_MAX_LENGTH` — 2000
 * chars, a bound written for `description`. Nothing about the column supported
 * it: migration 0066 widened `agents.system_prompt` to MEDIUMTEXT precisely
 * because "long system_prompt … routinely pushes past 64 KB". Meanwhile
 * `SlimPayloadValidator::buildValidatedCreateAgent()` applies **no** cap to
 * `create_agent`'s `system_prompt`, so an agent could be born with a 100 KB
 * persona and then be refused every attempt to edit it — and `read_agent`
 * returns that prompt verbatim, so the model is handed the string it is then
 * forbidden to send back.
 *
 * Asserted in both directions against the stored column, because the
 * asymmetry was only ever visible as a write that did not happen.
 *
 * @return array{0: AgentTool, 1: AgentService, 2: int}
 */
function makeAgentToolForPromptPatch(): array
{
    $key          = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $toolConfig   = new ToolConfigService(new SecurityManager($key), new NullLogger(), []);
    $llmConfig    = new LLMConfigService(new SecurityManager($key), []);
    $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
    $agentService = new AgentService();

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "agent-prompt-patch-{$seq}@example.com", AGENT_PROMPT_PATCH_PASSWORD);

    return [
        new AgentTool($agentService, $toolSettings, new AgentManifest($toolSettings, null)),
        $agentService,
        $userId,
    ];
}

if (!function_exists('agentPromptContext')) {
    function agentPromptContext(int $userId): PrincipalContext
    {
        return new PrincipalContext(
            principalId: createUserPrincipalPublic($userId),
            type: Spora\Models\Principal::TYPE_USER,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );
    }
}

describe('system_prompt is uncapped on both halves of the surface', function (): void {

    it('creates an agent whose system_prompt is far past the 2000-char description bound', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPromptPatch();
        $caller = $agents->createAgent($userId, ['name' => 'Caller']);
        $prompt = str_repeat('PERSONA. ', 40_000); // 320 KB — 160× DESC_MAX_LENGTH

        $created = $tool->execute([
            'action'  => 'create_agent',
            'payload' => ['name' => 'Long Persona', 'system_prompt' => $prompt],
        ], $caller->id, context: agentPromptContext($userId));

        expect($created->success)->toBeTrue()
            ->and($created->data['system_prompt'])->toBe($prompt)
            ->and(strlen((string) Capsule::table('agents')->where('id', $created->data['agent_id'])->value('system_prompt')))
            ->toBe(strlen($prompt));
    });

    it('updates that same agent with the same prompt — what create accepts, update accepts', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPromptPatch();
        $caller   = $agents->createAgent($userId, ['name' => 'Caller']);
        $prompt = str_repeat('PERSONA. ', 40_000);

        $created = $tool->execute([
            'action'  => 'create_agent',
            'payload' => ['name' => 'Round Trip', 'system_prompt' => $prompt],
        ], $caller->id, context: agentPromptContext($userId));
        $agentId = (int) $created->data['agent_id'];

        $updated = $tool->execute([
            'action'   => 'update_agent',
            'agent_id' => $agentId,
            'agent'    => ['system_prompt' => $prompt],
        ], $agentId, context: agentPromptContext($userId));

        // The regression: this was refused with "`system_prompt` must be 2000
        // chars or fewer", so an agent could be created with a persona and
        // then never edited again.
        expect($updated->success)->toBeTrue()
            ->and($updated->data['system_prompt'])->toBe($prompt)
            ->and((string) Capsule::table('agents')->where('id', $agentId)->value('system_prompt'))
            ->toBe($prompt);
    });

    it('still enforces the 2000-char bound on description', function (): void {
        // The cap did not leak the other way: `description` keeps the bound
        // `create_agent` already enforces on the same column.
        [$tool, $agents, $userId] = makeAgentToolForPromptPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Desc Bound']);

        $out = $tool->execute([
            'action'   => 'update_agent',
            'agent_id' => $agent->id,
            'agent'    => ['description' => str_repeat('d', 2001)],
        ], $agent->id, context: agentPromptContext($userId));

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('`description` must be 2000 chars or fewer');
    });

    it('still refuses a non-string system_prompt rather than truncating it', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPromptPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Type Check']);

        $out = $tool->execute([
            'action'   => 'update_agent',
            'agent_id' => $agent->id,
            'agent'    => ['system_prompt' => ['not', 'a', 'string']],
        ], $agent->id, context: agentPromptContext($userId));

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('`system_prompt` must be a string');
    });

    it('still accepts null to clear the prompt', function (): void {
        // The NULLABLE arm is independent of the length rule and must survive
        // it — an operator clearing a persona is a legitimate write.
        [$tool, $agents, $userId] = makeAgentToolForPromptPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Clearable', 'system_prompt' => 'original']);

        $out = $tool->execute([
            'action'   => 'update_agent',
            'agent_id' => $agent->id,
            'agent'    => ['system_prompt' => null],
        ], $agent->id, context: agentPromptContext($userId));

        expect($out->success)->toBeTrue()
            ->and(Capsule::table('agents')->where('id', $agent->id)->value('system_prompt'))->toBeNull();
    });
});
