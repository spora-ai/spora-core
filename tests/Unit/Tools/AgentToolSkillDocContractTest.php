<?php

declare(strict_types=1);

use Spora\Tools\AgentTool\SlimPayloadValidator;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The contract between `create_agent`'s refusal of `required_plugins` and the
 * bundled `agent-creation` skill.
 *
 * `skills/agent-creation/SKILL.md` documented `required_plugins` in five
 * places, including the canonical Step-1 `create_agent` example the skill
 * tells the model to copy. `SlimPayloadValidator::validateRequiredPlugins()`
 * refuses the key unconditionally, so the skill taught the primary flow as a
 * payload that can never succeed — while the tool's own `#[ToolOperation]`
 * description correctly omitted it. Two model-facing surfaces, opposite
 * instructions, and the skill was the wrong one.
 *
 * The behavioural half is already covered by `AgentToolTest`; what nothing
 * asserted was that the *documentation* matched it. That is the gap this file
 * closes: the refusal stayed green the entire time the skill was teaching a
 * payload that hits it.
 */
function agentCreationSkillBody(): string
{
    $path = BASE_PATH . '/skills/agent-creation/SKILL.md';
    expect(is_file($path))->toBeTrue("bundled skill missing at {$path}");

    return (string) file_get_contents($path);
}

describe('create_agent refuses required_plugins, and the agent-creation skill agrees', function (): void {

    it('refuses the key for every value shape, naming the operator endpoint', function (mixed $value): void {
        // Not a malformed value — the *key* is refused, because the LLM-facing
        // surface has nowhere to store it. A bare string, a slug array, a
        // Composer package array, an empty array and the `{item: [...]}`
        // unwrap quirk all take the same path.
        $out = (new SlimPayloadValidator())->validateCreateAgentPayload([
            'payload' => ['name' => 'X', 'required_plugins' => $value],
        ]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->success)->toBeFalse()
            ->and($out->content)->toContain('`required_plugins` is reserved for the operator-upload endpoint')
            ->and($out->content)->toContain('POST /api/v1/agent-templates/import');
    })->with([
        // Each entry is wrapped in its own array so Pest passes it as a single
        // positional argument. An unwrapped `['item' => [...]]` is read as
        // named parameters and fatals with "Unknown named parameter $item".
        'bare string'    => ['weather'],
        'slug array'     => [['weather']],
        'composer array' => [['spora-ai/spora-plugin-weather']],
        'object unwrap'  => [['item' => ['weather', 'calendar']]],
        'empty array'    => [[]],
    ]);

    it('does not teach required_plugins anywhere in the skill', function (): void {
        // The regression this file exists for. The shipped file carried the key
        // in five places: the Step-1 example, the slim-payload reference
        // table, the `get_available_tools` pre-flight checklist, the
        // `plugin_slug` bullet that told the model what *not* to send there,
        // and a common-mistakes row. Every one is a payload the tool refuses
        // above.
        expect(agentCreationSkillBody())->not->toContain('required_plugins');
    });

    it('keeps the useful half of what was removed around the key', function (): void {
        // Deleting the key must not have taken the surrounding explanation
        // with it: `plugin_slug` is still worth reading in the pre-flight
        // checklist, and plugins still install out-of-band.
        $body = agentCreationSkillBody();

        expect($body)->toContain('`plugin_slug` — `null` for core tools')
            ->and($body)->toContain('spora plugin install');
    });

    it('documents the shapes the code actually emits', function (): void {
        // Two drifts this release left in the same file: the manifest example
        // still carried `is_favorite`, which `AgentManifest::toArray()`
        // deliberately removed from the LLM-facing payload; and `list_agents`
        // was documented as `{agent_id, name, description}` while
        // `AgentTool::listAgents()` also emits `is_archived`.
        $body = agentCreationSkillBody();

        expect($body)->not->toContain('"is_favorite"')
            ->and($body)->toContain('{agent_id, name, description, is_archived}');
    });
});
