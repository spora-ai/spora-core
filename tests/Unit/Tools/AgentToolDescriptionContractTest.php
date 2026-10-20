<?php

declare(strict_types=1);

use Spora\Tools\AgentTool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * The contract between `AgentTool`'s own LLM-facing descriptions and the
 * behaviour they describe.
 *
 * A description is a promise to the model about what the call will do, and
 * nothing asserts the two agree. When they drift, the model is told
 * something false and acts on it — it reads a payload shape that is not the
 * one it gets, or it believes a key is writable when it is not.
 *
 * Each case below pins one description to the code that decides it. The
 * behavioural half of each is covered elsewhere; what is missing, and what
 * this file closes, is the *description* half.
 */
function agentToolOperationDescriptions(): array
{
    $reflection = new ReflectionClass(AgentTool::class);
    $byName     = [];
    foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
        /** @var ToolOperation $op */
        $op = $attr->newInstance();
        $byName[$op->name] = $op->description;
    }

    return $byName;
}

describe('the list_agents description matches the payload it promises', function (): void {

    it('names every key the row actually carries', function (): void {
        // `AgentTool::listAgents()` maps each row to agent_id, name,
        // description and is_archived. The description listed three of the
        // four, so a model reading it had no way to know the row carried
        // the archive flag — and an agent it should not delegate to looked
        // exactly like one it should.
        $byName = agentToolOperationDescriptions();

        expect($byName['list_agents'])->toContain('`agent_id`')
            ->and($byName['list_agents'])->toContain('`name`')
            ->and($byName['list_agents'])->toContain('`description`')
            ->and($byName['list_agents'])->toContain('`is_archived`');
    });

    it('does not promise a pinned flag the row does not carry', function (): void {
        // The slim row has no is_pinned key, so a description promising
        // "pinned state is preserved" pointed at a field the model could
        // never read back.
        $byName = agentToolOperationDescriptions();

        expect($byName['list_agents'])->not->toContain('pinned');
    });
});

describe('the read_agent description matches the manifest it promises', function (): void {

    it('names the key the manifest actually uses', function (): void {
        // `AgentManifest::toArray()` emits `agent_id`. The description said
        // `id`, so a model reading it back looked for a key that is not
        // there.
        $byName = agentToolOperationDescriptions();

        expect($byName['read_agent'])->toContain('`agent_id`')
            ->and($byName['read_agent'])->not->toContain('`id`,');
    });

    it('does not call the slim manifest a full configuration', function (): void {
        // The manifest carries no setting values and no per-tool display
        // metadata, and the agent-tool skill says so. Calling it the full
        // configuration invited the model to read a tool's settings off it.
        $byName = agentToolOperationDescriptions();

        expect($byName['read_agent'])->not->toContain('full configuration');
    });
});

describe('the update_agent description matches the patch rules it promises', function (): void {

    it('does not attribute the silent drop to a layer that does not drop it', function (): void {
        // The drop happens in `AgentService::applyAgentPatch`, which
        // intersects the patch with the service's editable-field list
        // before the row is written. "Dropped at the database layer"
        // pointed the model at a layer that never sees the key.
        $byName = agentToolOperationDescriptions();

        expect($byName['update_agent'])->not->toContain('dropped at the database layer');
    });
});

describe('the get_available_tools description matches the skills block it promises', function (): void {

    it('describes allowed as the effective list, not the agent own one', function (): void {
        // `SkillCatalogPresenter` reads `getEffectiveSettings()`, so
        // `allowed` is the list after the whole cascade — it can name
        // entries inherited from a group or a user principal that the
        // agent's own row never held. The agent-tool skill says exactly
        // that; the tool description said "the agent's own".
        $byName = agentToolOperationDescriptions();

        expect($byName['get_available_tools'])->toContain('effective')
            ->and($byName['get_available_tools'])->not->toContain("own `allowed_skills`");
    });
});
