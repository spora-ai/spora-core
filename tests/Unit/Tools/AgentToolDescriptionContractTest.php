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
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigService;
use Spora\Tools\AgentTool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\CalculatorTool;
use Spora\Tools\TimeTool;

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

describe('the list_agents description matches the principal scope it promises', function (): void {

    it('names the groups whose agents the list also carries', function (): void {
        // The list is scoped to the *user's* principals — their own
        // user-principal plus the group-principals of every group they
        // belong to. "Visible to the current user" said nothing about the
        // groups, so a model met a group agent in the list with no way to
        // have predicted it.
        $byName = agentToolOperationDescriptions();

        expect($byName['list_agents'])->toContain('every group they belong to');
    });

    it('warns that being listed is not the same as being reachable', function (): void {
        // `list_agents` filters on `visiblePrincipalIds` (any group
        // membership, any role); `read_agent` filters on
        // `callerControlsPrincipal` (group requires owner or admin). A group
        // agent the caller merely belongs to is listed and then refused, and
        // the refusal reads "not owned by this user" — which reads as a
        // contradiction unless the description says visibility ≠ access.
        $byName = agentToolOperationDescriptions();

        expect($byName['list_agents'])->toContain('own or administer the group');
    });
});

describe('the group scope the list_agents description promises', function (): void {

    it('lists a group agent the user only belongs to, and refuses to read it', function (): void {
        // The behavioural half of the two description claims above, and the
        // guard that keeps the wording from being "corrected" the wrong way.
        // A group agent the user is a plain member of IS in the list — so
        // the description has to say so — and reading it IS refused — so
        // the description has to say that too. Narrow the visibility to the
        // user's own principal and this fails, which is what stops a
        // future edit from replacing one wrong description with another.
        // It passes on the pre-fix code too, because the behaviour it
        // anchors was never wrong — only the description of it was.
        $key        = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $toolConfig = new ToolConfigService(new SecurityManager($key), new NullLogger(), [CalculatorTool::class, TimeTool::class]);
        $settings   = new AgentToolSettingsService($toolConfig, new LLMConfigService(new SecurityManager($key), []));
        // The resolver is what widens the query past the caller's own
        // principal to the group-principals of their groups. Production
        // wires it (ContainerDefinitions); a bare `new AgentService()` falls
        // back to the legacy user-principal-only query.
        $resolver = new PrincipalResolver();
        $agents   = new AgentService(null, null, $resolver);
        $tool     = new AgentTool($agents, $settings, new AgentManifest($settings, null));

        static $seq = 0;
        $seq++;
        $userId = (int) Capsule::table('users')->insertGetId([
            'email'      => "agent-tool-scope-{$seq}@example.com",
            'username'   => "agent_tool_scope_{$seq}",
            'password'   => str_repeat("\0", 60),
            'status'     => 1,
            'verified'   => 1,
            'resettable' => 1,
            'roles_mask' => 0,
            'registered' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $ownAgent = $agents->createAgent($userId, ['name' => 'Own Agent']);

        // The user is a plain `member` of this group — not owner, not admin.
        $groupPrincipalId = $this->makeGroupPrincipal($userId, 'Scope Group');
        $groupAgent       = $agents->createAgent($userId, ['name' => 'Group Agent'], $groupPrincipalId);

        $context = new PrincipalContext(
            principalId: $this->createUserPrincipal($userId),
            type: Spora\Models\Principal::TYPE_USER,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );

        $listed = $tool->execute(['action' => 'list_agents'], $ownAgent->id, null, $context);
        $ids    = array_column($listed->data['agents'] ?? [], 'agent_id');

        expect($listed->success)->toBeTrue()
            ->and($ids)->toContain($groupAgent->id)
            ->and($ids)->toContain($ownAgent->id);

        $read = $tool->execute(
            ['action' => 'read_agent', 'agent_id' => $groupAgent->id],
            $ownAgent->id,
            null,
            $context,
        );

        expect($read->success)->toBeFalse()
            ->and($read->content)->toContain('not found or not owned by this user');
    });
});
