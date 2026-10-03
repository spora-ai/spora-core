<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Services\AgentManifest;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\AgentTool;

defined('AGENT_PATCH_PASSWORD') || define('AGENT_PATCH_PASSWORD', 'Password1!');

/**
 * `update_agent` value types, against the real database.
 *
 * `update_agent` had no type validation at all: the patch went straight to
 * `AgentService::updateAgentByAgentId`, which filters the *column set* but
 * never the *value types*. A provider that emits scalars as strings wrote the
 * literal string `'false'` into a boolean column, which reads back as true —
 * so unarchiving an agent archived it, and the response confirmed it. These
 * assert the stored column, because the defect was in what got written, not
 * in what the call reported.
 *
 * @return array{0: AgentTool, 1: AgentService, 2: int}
 */
function makeAgentToolForPatch(): array
{
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $toolConfig  = new ToolConfigService(new SecurityManager($key), new NullLogger(), []);
    $llmConfig   = new LLMConfigService(new SecurityManager($key), []);
    $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
    $agentService = new AgentService();

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "agent-patch-{$seq}@example.com", AGENT_PATCH_PASSWORD);

    return [
        new AgentTool($agentService, $toolSettings, new AgentManifest($toolSettings, null)),
        $agentService,
        $userId,
    ];
}

function storedColumn(int $agentId, string $column): mixed
{
    return Capsule::table('agents')->where('id', $agentId)->value($column);
}

describe('update_agent — a stringified boolean is read, not cast', function (): void {

    it('unarchives when the flag arrives as the string "false"', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Flags']);

        $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['is_archived' => true, 'is_pinned' => true],
        ], $agent->id, $userId);
        expect((int) storedColumn($agent->id, 'is_archived'))->toBe(1);

        // The regression: `(bool) "false"` is true, so this used to store the
        // string 'false' and the agent stayed archived — with a success response
        // whose manifest agreed it was archived.
        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['is_archived' => 'false', 'is_pinned' => 'false'],
        ], $agent->id, $userId);

        expect($result->success)->toBeTrue()
            ->and(storedColumn($agent->id, 'is_archived'))->toBe(0)
            ->and(storedColumn($agent->id, 'is_pinned'))->toBe(0)
            ->and($result->data['is_archived'])->toBeFalse()
            ->and($result->data['is_pinned'])->toBeFalse();
    });

    it('stores a real integer, never the string it arrived as', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Typed']);

        $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['is_archived' => 'true', 'allow_followup' => 'false'],
        ], $agent->id, $userId);

        // A string in a boolean column makes the row invisible to a
        // `WHERE is_archived = 1` filter while the manifest calls it archived.
        expect(storedColumn($agent->id, 'is_archived'))->toBe(1)
            ->and(storedColumn($agent->id, 'allow_followup'))->toBe(0);
    });

    it('accepts the integer spellings the schedule tool already accepts', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Numeric Booleans']);

        $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['is_archived' => 1, 'is_pinned' => '1'],
        ], $agent->id, $userId);
        expect((int) storedColumn($agent->id, 'is_archived'))->toBe(1)
            ->and((int) storedColumn($agent->id, 'is_pinned'))->toBe(1);

        $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['is_archived' => 0, 'is_pinned' => '0'],
        ], $agent->id, $userId);
        expect((int) storedColumn($agent->id, 'is_archived'))->toBe(0)
            ->and((int) storedColumn($agent->id, 'is_pinned'))->toBe(0);
    });

    it('refuses a value that names no flag', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Refused']);

        foreach (['yes', 'archived', 2] as $value) {
            $result = $tool->execute([
                'action' => 'update_agent', 'agent_id' => $agent->id,
                'agent'  => ['is_archived' => $value],
            ], $agent->id, $userId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('must be a boolean');
        }

        expect((int) storedColumn($agent->id, 'is_archived'))->toBe(0);
    });
});

describe('update_agent — a stringified number is range-checked', function (): void {

    it('accepts a quoted number inside the bounds', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'In Range']);

        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['max_steps' => '25', 'retry_after_minutes' => '3', 'max_retries' => '2'],
        ], $agent->id, $userId);

        expect($result->success)->toBeTrue()
            ->and((int) storedColumn($agent->id, 'max_steps'))->toBe(25)
            ->and((int) storedColumn($agent->id, 'retry_after_minutes'))->toBe(3)
            ->and((int) storedColumn($agent->id, 'max_retries'))->toBe(2);
    });

    it('refuses a quoted number that is out of bounds', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Out Of Range']);
        $before = (int) storedColumn($agent->id, 'max_steps');

        $high = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['max_steps' => '999'],
        ], $agent->id, $userId);

        expect($high->success)->toBeFalse()
            ->and($high->content)->toContain('`max_steps` must be an integer in 1..100')
            ->and((int) storedColumn($agent->id, 'max_steps'))->toBe($before);

        $negative = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['retry_after_minutes' => '-5'],
        ], $agent->id, $userId);

        expect($negative->success)->toBeFalse()
            ->and($negative->content)->toContain('`retry_after_minutes` must be an integer in 0..')
            ->and((int) storedColumn($agent->id, 'retry_after_minutes'))->toBe(0);
    });

    it('refuses a fractional or non-numeric value rather than truncating it', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Not Whole']);

        foreach (['12.5', 'lots', ''] as $value) {
            $result = $tool->execute([
                'action' => 'update_agent', 'agent_id' => $agent->id,
                'agent'  => ['max_steps' => $value],
            ], $agent->id, $userId);

            expect($result->success)->toBeFalse();
        }
    });

    it('applies nothing when one field in the patch is refused', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Atomic Patch']);

        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['name' => 'Should Not Land', 'max_steps' => '999'],
        ], $agent->id, $userId);

        expect($result->success)->toBeFalse()
            ->and(storedColumn($agent->id, 'name'))->toBe($agent->name);
    });
});

describe('update_agent — the documented surface', function (): void {

    it('accepts a null on a nullable text field', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Nullable', 'description' => 'has one']);

        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['description' => null],
        ], $agent->id, $userId);

        expect($result->success)->toBeTrue()
            ->and(storedColumn($agent->id, 'description'))->toBeNull();
    });

    it('forwards an unknown key for the service to filter', function (): void {
        // The allowlist belongs to AgentService::EDITABLE_AGENT_FIELDS, and
        // AgentToolTest pins that the tool layer forwards rather than filters.
        // This validator owns value types, so it must not start refusing keys
        // that used to pass straight through.
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Forwarded']);

        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['not_a_field' => 'ignored downstream'],
        ], $agent->id, $userId);

        $attributes = Spora\Models\Agent::find($agent->id)?->getAttributes() ?? [];

        expect($result->success)->toBeTrue()
            ->and($attributes)->not->toHaveKey('not_a_field');
    });

    it('refuses the driver-config columns an LLM must not repoint', function (): void {
        // These are writable through the service allowlist but are not part of
        // this surface's contract, and two of them decide which model and
        // credential set an agent runs on. Nothing here lets a model read the
        // valid ids, so it would be guessing at a consequential value.
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Repointed']);

        foreach (['llm_driver_config_id', 'speech_driver_config_id', 'voice_message_retention_count'] as $key) {
            $result = $tool->execute([
                'action' => 'update_agent', 'agent_id' => $agent->id,
                'agent'  => [$key => 1],
            ], $agent->id, $userId);

            expect($result->success)->toBeFalse("{$key} must not be writable through the tool")
                ->and($result->content)->toContain("'{$key}' is not writable through this tool")
                ->and($result->content)->toContain('an operator sets it');
        }

        expect(storedColumn($agent->id, 'llm_driver_config_id'))->toBeNull();
    });

    it('refuses the whole patch when one key is not the tool\'s to write', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Mixed Patch']);

        // A silent drop would read to the model as "that landed", which is the
        // same failure shape as the dead override row.
        $result = $tool->execute([
            'action' => 'update_agent', 'agent_id' => $agent->id,
            'agent'  => ['name' => 'Should Not Land', 'llm_driver_config_id' => 1],
        ], $agent->id, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('the whole patch is refused')
            ->and(storedColumn($agent->id, 'name'))->toBe($agent->name);
    });

    it('refuses an empty name and an over-long name', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $agent = $agents->createAgent($userId, ['name' => 'Named']);

        foreach (['', '   ', str_repeat('x', 201)] as $value) {
            $result = $tool->execute([
                'action' => 'update_agent', 'agent_id' => $agent->id,
                'agent'  => ['name' => $value],
            ], $agent->id, $userId);

            expect($result->success)->toBeFalse();
        }

        expect(storedColumn($agent->id, 'name'))->toBe($agent->name);
    });
});

describe('create_agent — stringified scalars', function (): void {

    it('reads a quoted boolean and number instead of casting them', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $caller = $agents->createAgent($userId, ['name' => 'Caller']);

        $result = $tool->execute([
            'action'  => 'create_agent',
            'payload' => ['name' => 'Quoted', 'max_steps' => '20', 'allow_followup' => 'false'],
        ], $caller->id, $userId);

        expect($result->success)->toBeTrue()
            ->and($result->data['max_steps'])->toBe(20)
            // `(bool) "false"` would be true — the create path had the same cast.
            ->and($result->data['allow_followup'])->toBeFalse();
    });

    it('still refuses a quoted number that is out of bounds', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $caller = $agents->createAgent($userId, ['name' => 'Caller']);

        $result = $tool->execute([
            'action'  => 'create_agent',
            'payload' => ['name' => 'Too Many Steps', 'max_steps' => '999'],
        ], $caller->id, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`max_steps` must be a whole number in 1..100');
    });

    it('still refuses a quoted boolean that names no flag', function (): void {
        [$tool, $agents, $userId] = makeAgentToolForPatch();
        $caller = $agents->createAgent($userId, ['name' => 'Caller']);

        $result = $tool->execute([
            'action'  => 'create_agent',
            'payload' => ['name' => 'Vague', 'allow_followup' => 'yes'],
        ], $caller->id, $userId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`allow_followup` must be a boolean');
    });
});
