<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\AgentTool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;
use Spora\Tools\TimeTool;

/**
 * @return array{0: AgentTool, 1: AgentServiceInterface, 2: AgentToolSettingsServiceInterface}
 */
function makeAgentTool(): array
{
    // AgentTemplateImporter + AgentTemplateValidator are final and cannot
    // be mocked directly; use real instances. Validator is parameter-less,
    // importer needs a real ToolConfigService + PluginLoader + Paths.
    $importer = new AgentTemplateImporter(
        new Spora\Services\ToolConfigService(
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new Psr\Log\NullLogger(),
        ),
        new Spora\Plugins\PluginLoader([], null),
        new Spora\Core\Paths(BASE_PATH),
    );
    $validator = new AgentTemplateValidator();
    /** @var AgentServiceInterface&MockInterface $agentService */
    $agentService = Mockery::mock(AgentServiceInterface::class);
    /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
    $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
    // Real AgentManifest wired to the same mocked settings service so
    // tests can drive the per-tool/per-op state through it.
    $manifest = new Spora\Services\AgentManifest($toolSettings, null);

    return [
        new AgentTool($agentService, $toolSettings, $manifest),
        $agentService,
        $toolSettings,
    ];
}

/**
 * Variant of makeAgentTool() that also stubs the optional PluginLoader and
 * ToolIconResolver collaborators. Used by get_available_tools tests that
 * exercise the plugin-qualified call_name and icon paths.
 *
 * PluginLoader is `final` and therefore cannot be mocked with Mockery, so
 * we hand back a real loader with a stub plugin injected via reflection —
 * the same pattern used by PluginLoaderTest (see
 * tests/Unit/Plugins/PluginLoaderTest.php:395-400).
 *
 * @return array{0: AgentTool, 1: AgentServiceInterface, 2: AgentToolSettingsServiceInterface, 3: Spora\Plugins\PluginLoader, 4: Spora\Services\ToolIconResolver}
 */
function makeAgentToolWithPlugins(): array
{
    $importer = new AgentTemplateImporter(
        new Spora\Services\ToolConfigService(
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new Psr\Log\NullLogger(),
        ),
        new Spora\Plugins\PluginLoader([], null),
        new Spora\Core\Paths(BASE_PATH),
    );
    $validator = new AgentTemplateValidator();
    /** @var AgentServiceInterface&MockInterface $service */
    $service = Mockery::mock(AgentServiceInterface::class);
    /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
    $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
    // Real PluginLoader; tests inject stubs via reflection.
    $pluginLoader = new Spora\Plugins\PluginLoader([], null);
    /** @var Spora\Services\ToolIconResolver&MockInterface $iconResolver */
    $iconResolver = Mockery::mock(Spora\Services\ToolIconResolver::class);
    $manifest = new Spora\Services\AgentManifest($toolSettings, $iconResolver);

    return [
        new AgentTool($service, $toolSettings, $manifest, new AgentTool\AgentToolCollaborators(
            pluginLoader: $pluginLoader,
            iconResolver: $iconResolver,
        )),
        $service,
        $toolSettings,
        $pluginLoader,
        $iconResolver,
    ];
}

function stubAgent(int $id = 1, string $name = 'Test Agent', ?string $notes = null): Agent
{
    $agent          = new Agent();
    $agent->id      = $id;
    $agent->name    = $name;
    $agent->notes   = $notes;
    $agent->principal_id = createUserPrincipalPublic(99);
    return $agent;
}

/**
 * Build a fully-populated Agent fixture for the canonical manifest path.
 * AgentManifest reads `max_steps`, `allow_followup`, `is_pinned`, etc.
 * directly off the model; the legacy stubAgent() leaves them null which
 * trips the PHPStan-checked casts. Returns the populated Agent.
 */
function stubManifestAgent(int $id, string $name = 'Test'): Agent
{
    $agent = new Agent();
    $agent->id                  = $id;
    $agent->principal_id = createUserPrincipalPublic(99);
    $agent->name                = $name;
    $agent->description         = null;
    $agent->system_prompt       = null;
    $agent->notes               = null;
    $agent->max_steps           = 10;
    // Eloquent casts bool props as `bool`, so the fixture must hold true/false
    // — assigning 0/1 trips PHPStan and silently deserialises to false either
    // way at runtime.
    $agent->allow_followup      = true;
    $agent->is_pinned           = false;
    $agent->is_archived         = false;
    $agent->is_favorite         = false;
    $agent->retry_after_minutes = 0;
    $agent->max_retries         = 0;
    return $agent;
}

describe('AgentTool::execute — read_agent_configuration (deprecated, soft-redirect)', function (): void {
    // `read_agent_configuration` is the legacy name. It now redirects to
    // `read_agent(self)` so any task that learned the old name still
    // gets the canonical manifest. The deprecation note is prepended to
    // `result_content`. Hard-remove in a later release.

    test('soft-redirects to read_agent(self) and returns the canonical manifest', function (): void {
        // Real DB: the redirect path goes through read_agent(self),
        // which queries the agents table by id.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'redirect-self@example.com');
        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $callingId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Alpha',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $result = $tool->execute(
            ['action' => 'read_agent_configuration'],
            $callingId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['agent_id'])->toBe($callingId)
            ->and($data['name'])->toBe('Alpha');
        // The deprecation note reaches the LLM in `result_content` so
        // any model trained on the old operation name pivots.
        expect($result->content)
            ->toContain('_(deprecated: read_agent_configuration')
            ->toContain("## Agent #{$callingId} \u{2014} Alpha");
    });

    test('returns failure when the calling agent row does not exist', function (): void {
        // No real row, so the redirect → read_agent(self) returns
        // AGENT_NOT_FOUND. Kept as a smoke test for the failure path.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'redirect-missing@example.com');
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'read_agent_configuration'],
            999,
            $ownerId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });
});

describe('AgentTool::execute — write_notes', function (): void {
    test('rejects missing content', function (): void {
        // Agent existence is checked first, so the LLM sees "Agent not
        // found." rather than a content-shape complaint when both are wrong.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'write_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });

    test('rejects missing content when the agent exists', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(['action' => 'write_notes'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('content is required');
    });

    test('rejects invalid mode', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'x', 'mode' => 'nuke'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('invalid mode');
    });

    test('rejects empty content with no-op return', function (): void {
        // Empty content on append/prepend must not pile up separator
        // characters across repeated LLM calls.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $agent->notes    = 'preserved';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        // updateAgentByAgentId must NOT be called when content is empty.
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => '', 'mode' => 'append'],
            7,
            99,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['mode'])->toBe('append')
            ->and($result->data['notes'])->toBe('preserved');
    });

    test('appends by default and persists via updateAgentByAgentId', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $agent->notes    = 'pre-existing';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['notes' => "pre-existing\n\nnew content"])
            ->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'new content'],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('append')
            ->and($data['notes'])->toBe("pre-existing\n\nnew content")
            ->and($data['length'])->toBe(mb_strlen("pre-existing\n\nnew content"));
    });

    test('prepends when mode=prepend is passed', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $agent->notes    = 'existing';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['notes' => "new content\n\nexisting"])
            ->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'new content', 'mode' => 'prepend'],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('prepend')
            ->and($data['notes'])->toBe("new content\n\nexisting");
    });

    test('write_notes_overwrite replaces wholesale and returns mode=overwrite', function (): void {
        // The destructive overwrite path is a separate operation that
        // requires operator approval when enabled. Verify the body of
        // write_notes_overwrite: it discards the LLM's `mode` arg and
        // overwrites the agent's notes wholesale.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $agent->notes    = 'existing';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['notes' => 'replacement'])
            ->andReturn($agent);

        $result = $tool->execute(
            [
                'action'  => 'write_notes_overwrite',
                'content' => 'replacement',
                'mode'    => 'append', // ignored — write_notes_overwrite forces overwrite
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('overwrite')
            ->and($data['notes'])->toBe('replacement');
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'x'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — read_notes', function (): void {
    test('returns notes and length when the agent exists', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $agent->notes    = '# runbook';
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(['action' => 'read_notes'], 7, 99);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['notes'])->toBe('# runbook')
            ->and($data['length'])->toBe(9);
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'read_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — get_available_tools', function (): void {
    test('enriches per-agent status with presenter metadata and returns a versioned JSON payload as content', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\CalculatorTool',
                'tool_name'        => 'calculator',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeTrue();
        // `$data` keeps the legacy shape for the operator UI / audit log.
        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        $first = $rows[0];
        expect($first['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($first['tool_name'])->toBe('calculator')
            ->and($first['is_enabled'])->toBeFalse()
            ->and($first['needs_configuration'])->toBeFalse();

        // `$content` is the v2 LLM-facing contract. The slim shape drops
        // `tool_name` (overlaps with `tool_class`), `call_name` (used by
        // tool invocation, not agent configuration), `category`,
        // `icon`, and the nested `source` — replaced by a flat
        // `plugin_slug`.
        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['version'])->toBe(2)
            ->and($payload['count'])->toBe(1);
        $tool = $payload['tools'][0];
        expect($tool['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($tool['display_name'])->toBeString()
            ->and($tool['description'])->toBeString()
            ->and($tool['plugin_slug'])->toBeNull()
            ->and($tool['enabled'])->toBeFalse()
            ->and($tool['ready_to_enable'])->toBeTrue()
            ->and($tool['missing_required'])->toBe([]);
        // Old fields are gone — the slim shape dropped them on purpose.
        expect($tool)->not->toHaveKey('tool_name')
            ->and($tool)->not->toHaveKey('call_name')
            ->and($tool)->not->toHaveKey('category')
            ->and($tool)->not->toHaveKey('icon')
            ->and($tool)->not->toHaveKey('source');
    });

    test('flags needs_configuration when can_enable is false', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\ReadUrlTool',
                'tool_name'        => 'read_url',
                'is_enabled'       => false,
                'can_enable'       => false,
                'missing_required' => ['allowed_hosts'],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        expect($rows[0]['needs_configuration'])->toBeTrue()
            ->and($rows[0]['missing_required'])->toBe(['allowed_hosts']);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['tools'][0]['ready_to_enable'])->toBeFalse()
            ->and($payload['tools'][0]['missing_required'])->toBe(['allowed_hosts']);
    });

    test('qualifies call_name with the plugin slug for plugin-owned tools', function (): void {
        [$tool, $service, $toolSettings, $pluginLoader, $iconResolver] = makeAgentToolWithPlugins();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        /** @var Spora\Services\ToolIconResolver&MockInterface $iconResolver */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        // We use a real Spora core class as the target so the
        // `class-string<ToolInterface>` return type is satisfied, and the
        // plugin mapping is supplied by a stub plugin injected via
        // reflection (PluginLoader is final and cannot be mocked).
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => TimeTool::class,
                'tool_name'        => 'time',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $tavilyPlugin = new class extends Spora\Plugins\AbstractPlugin {
            public function getName(): string
            {
                return 'Tavily';
            }
            /**
             * @return list<class-string<Spora\Tools\ToolInterface>>
             */
            public function tools(): array
            {
                return [TimeTool::class];
            }
        };
        $ref = new ReflectionClass($pluginLoader);
        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setValue($pluginLoader, ['tavily' => $tavilyPlugin]);
        $manifestsProp = $ref->getProperty('pluginManifests');
        $manifestsProp->setValue($pluginLoader, ['tavily' => ['name' => 'Tavily', 'icon' => 'search']]);

        $iconResolver->allows('resolve')->andReturn('search');

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $row = $payload['tools'][0];
        // v2 slim shape: plugin slug is a flat field, not a `source` object.
        expect($row['plugin_slug'])->toBe('tavily')
            ->and($row)->not->toHaveKey('call_name')
            ->and($row)->not->toHaveKey('source');
    });

    test('renders per-operation enabled + requires_approval from effective state', function (): void {
        // Use AgentTool as the target: it ships a mix of
        // #[ToolOperation] entries with varied
        // enabledByDefault / requiresApprovalByDefault flags, and
        // `getToolsOperations` is easy to drive from a mock. The
        // presenter enumerates operations in declaration order, so we
        // pin `read_agent` (enabled, no approval) and
        // `update_agent` (disabled, requires approval) as the two
        // anchors for the assertion.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => AgentTool::class,
                'tool_name'        => 'agent',
                'is_enabled'       => true,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([
            [
                'tool_class'                  => AgentTool::class,
                'operation'                   => 'read_agent',
                'effective_enabled'           => true,
                'effective_requires_approval' => false,
            ],
            [
                'tool_class'                  => AgentTool::class,
                'operation'                   => 'update_agent',
                'effective_enabled'           => false,
                'effective_requires_approval' => true,
            ],
        ]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $opsByName = [];
        foreach ($payload['tools'][0]['operations'] as $op) {
            if (in_array($op['name'], ['read_agent', 'update_agent'], true)) {
                $opsByName[$op['name']] = $op;
            }
        }
        expect($opsByName['read_agent']['enabled'])->toBeTrue()
            ->and($opsByName['read_agent']['requires_approval'])->toBeFalse();
        expect($opsByName['update_agent']['enabled'])->toBeFalse()
            ->and($opsByName['update_agent']['requires_approval'])->toBeTrue();
    });

    test('falls back to operation defaults when no per-agent override exists', function (): void {
        // `getToolsOperations` returns rows only for *enabled* tools, so a
        // not-yet-enabled tool will not appear in the effective map at all.
        // The presenter should fall back to the operation's
        // enabledByDefault / requiresApprovalByDefault from #[ToolOperation].
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->principal_id = createUserPrincipalPublic(99);
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => AgentTool::class,
                'tool_name'        => 'agent',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $opsByName = [];
        foreach ($payload['tools'][0]['operations'] as $op) {
            $opsByName[$op['name']] = $op;
        }
        // read_agent: enabledByDefault=false, requiresApprovalByDefault=false —
        // same defaults the existing agent had before the action split
        // (read_agent_configuration was also a disabled-by-default op
        // until the first time the operator enabled it).
        expect($opsByName['read_agent']['enabled'])->toBeFalse()
            ->and($opsByName['read_agent']['requires_approval'])->toBeFalse();
        // update_agent: enabledByDefault=false, requiresApprovalByDefault=true
        expect($opsByName['update_agent']['enabled'])->toBeFalse()
            ->and($opsByName['update_agent']['requires_approval'])->toBeTrue();
    });

    test('returns failure when the agent does not exist', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found');
    });
});

describe('AgentTool::execute — create_agent', function (): void {
    // The LLM-facing create_agent accepts a SLIM payload
    // (name, description, system_prompt, max_steps, allow_followup,
    // retry_after_minutes, max_retries, required_plugins). The full
    // agent-template.schema.json shape is reserved for the
    // operator-upload endpoint at POST /api/v1/agent-templates/import.
    //
    // `user_id` is sourced from the calling Agent's row — async callers
    // don't have a session, and the new agent is owned by the same user
    // as the agent that created it. See `listAgents()` for the same
    // pattern.

    test('fails closed when the calling agent cannot be resolved', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);
        $service->shouldNotReceive('createAgent');

        $result = $tool->execute(
            ['action' => 'create_agent', 'payload' => ['name' => 'X']],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found');
    });

    test('rejects missing payload', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));

        $result = $tool->execute(['action' => 'create_agent'], 7, null);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`payload`');
    });

    test('rejects the legacy `agent{}` wrapper with a literal fix', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'name'  => 'X',
                    'agent' => ['description' => 'wrong-location'],
                ],
            ],
            7,
            null,
        );

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('do NOT wrap fields in an `agent{}` block');
    });

    test('rejects the legacy `tools[]` block with a redirect to configure_tools', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'name'  => 'X',
                    'tools' => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true]],
                ],
            ],
            7,
            null,
        );

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('`tools[]` is no longer accepted here')
            ->and($out->content)->toContain('configure_tools(agent_id: N');
    });

    test('rejects required_plugins even when sent as a valid slug array', function (): void {
        // `required_plugins` is reserved for the operator-upload
        // agent-template schema; the LLM-facing slim payload will
        // never store it, so we surface a clear "not accepted here"
        // message instead of silently dropping the field.
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));

        foreach ([['weather'], ['item' => 'weather']] as $bad) {
            $out = $tool->execute(
                [
                    'action'  => 'create_agent',
                    'payload' => [
                        'name'             => 'X',
                        'required_plugins' => $bad,
                    ],
                ],
                7,
                null,
            );

            expect($out->success)->toBeFalse()
                ->and($out->content)->toContain('`required_plugins` is reserved for the operator-upload endpoint')
                ->and($out->content)->toContain('POST /api/v1/agent-templates/import');
        }
    });

    test('persists retry_after_minutes and max_retries from the slim payload', function (): void {
        // The slim payload validates retry_after_minutes / max_retries
        // for shape and forwards them into AgentService::createAgent.
        // Pin both that the values land on the createAgent call (a
        // silent drop would have left the DB row at the default 0).
        // The owning user_id comes from the calling agent's row, not
        // from the dispatcher's parameter.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7, name: 'Caller'));
        $service->shouldReceive('createAgent')
            ->once()
            ->withArgs(function (int $userIdArg, array $data, ?int $principalIdArg = null): bool {
                return $userIdArg === 99
                    && $data['retry_after_minutes'] === 30
                    && $data['max_retries'] === 2;
            })
            ->andReturn(stubManifestAgent(id: 42, name: 'Retries Agent'));
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'name'                => 'Retries Agent',
                    'retry_after_minutes' => 30,
                    'max_retries'         => 2,
                ],
            ],
            7,
            null,
        );

        expect($out->success)->toBeTrue();
    });

    test('happy path: slim payload creates the agent and emits the manifest', function (): void {
        // Real AgentService writes would hit the DB; mock the LLM-side
        // helper to return a fully-populated Agent fixture so the
        // manifest renders cleanly.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7, name: 'Caller'));
        $agent = stubManifestAgent(id: 42, name: 'New Agent');
        $agent->description   = 'created via AgentTool';
        $agent->system_prompt = 'You are the New Agent.';
        $agent->max_steps = 12;
        $service->shouldReceive('createAgent')
            ->once()
            ->withArgs(function (int $userIdArg, array $data, ?int $principalIdArg = null): bool {
                return $userIdArg === 99
                    && ($data['name'] ?? null) === 'New Agent'
                    && ($data['max_steps'] ?? null) === 12
                    && ($data['allow_followup'] ?? null) === true;
            })
            ->andReturn($agent);
        // AgentManifest reads the per-agent status + ops; empty lists
        // are fine when the test is asserting shape, not tool contents.
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'name'                => 'New Agent',
                    'description'         => 'created via AgentTool',
                    'system_prompt'       => 'You are the New Agent.',
                    'max_steps'           => 12,
                    'allow_followup'      => true,
                ],
            ],
            7,
            null,
        );

        expect($out->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $out->data;
        // Canonical manifest shape, not the legacy {'agent': {...}, 'tools_enabled': [...]}.
        expect($data['agent_id'])->toBe(42)
            ->and($data['name'])->toBe('New Agent')
            ->and($data['description'])->toBe('created via AgentTool')
            ->and($data['system_prompt'])->toBe('You are the New Agent.')
            ->and($data['max_steps'])->toBe(12)
            ->and($data['allow_followup'])->toBeTrue()
            ->and($data['tools'])->toBe([]);

        // result_content is the standard Markdown wrapper. The intro
        // line names the new agent id and points the LLM at the next
        // two calls (configure_tools + read_agent).
        expect($out->content)
            ->toContain('Configure tools next with `configure_tools(agent_id:')
            ->toContain('## Agent #42');
    });
});

describe('AgentTool::execute — unknown action', function (): void {
    test('returns failure', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'teleport'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Invalid action');
    });
});

describe('AgentTool::describeAction', function (): void {
    test('renders the mode for write_notes', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'write_notes']))
            ->toBe('Write notes on this agent (mode: append).');

        expect($tool->describeAction(['action' => 'write_notes', 'mode' => 'prepend']))
            ->toBe('Write notes on this agent (mode: prepend).');
    });

    test('renders the destructive path for write_notes_overwrite', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'write_notes_overwrite']))
            ->toBe("Replace the agent's notes wholesale (destructive).");
    });

    test('renders the entry count for configure_tools', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'configure_tools']))
            ->toBe("Configure this agent's toolset (0 entries).");

        expect($tool->describeAction([
            'action' => 'configure_tools',
            'tools'  => [
                ['tool_class' => 'A', 'enabled' => true],
                ['tool_class' => 'B', 'enabled' => false],
            ],
        ]))->toBe("Configure this agent's toolset (2 entries).");
    });

    test('renders the identifier for read_agent', function (): void {
        [$tool] = makeAgentTool();

        // No agent_id: falls through to the calling agent.
        expect($tool->describeAction(['action' => 'read_agent']))
            ->toBe('Read agent state (calling agent).');
        // Targeted agent by numeric id.
        expect($tool->describeAction(['action' => 'read_agent', 'agent_id' => 42]))
            ->toBe('Read agent state (agent_id: 42).');
    });
});

describe('AgentTool::execute — configure_tools', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => []],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('rejects a non-array `tools` argument', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => 'not-an-array'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('must be an array');
    });

    test('rejects a tool entry missing tool_class', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => [['enabled' => true]]],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('missing `tool_class`');
    });

    test('rejects a malformed operations entry', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    'operations' => [['enabled' => true]],
                ]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('operations[0][0]');
    });

    test('enables a tool on the calling agent and returns the manifest readback', function (): void {
        // configure_tools without agent_id operates on the calling agent
        // — the resolver hits the live agents table, so the row has to
        // exist for the test to drive a successful apply.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'configure-self@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callingId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Calling',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')
            ->once()
            ->with($callingId, $ownerId, 'Spora\\Tools\\TimeTool')
            ->andReturn(['tool' => ['tool_class' => 'Spora\\Tools\\TimeTool', 'tool_name' => 'time']]);
        $toolSettings->shouldNotReceive('disableTool');
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\TimeTool',
                'tool_name'        => 'time',
                'is_enabled'       => true,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true, 'operations' => []]],
            ],
            $callingId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        // configure_tools confirmation now returns the canonical manifest.
        expect($data['agent_id'])->toBe($callingId)
            ->and($data)->toHaveKey('tools');
    });

    test('with enabled false removes the tool', function (): void {
        // configure_tools without agent_id operates on the calling agent
        // — the resolver hits the live agents table, so the row has to
        // exist for the test to drive a successful apply.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'configure-remove@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callingId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Calling',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('disableTool')
            ->once()
            ->with($callingId, $ownerId, 'Spora\\Tools\\TimeTool');
        $toolSettings->shouldNotReceive('enableTool');
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => false]],
            ],
            $callingId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
    });

    test('sets per-operation auto_approve overrides via patchOperationOverride', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'configure-op-override@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callingId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Calling',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->allows('enableTool')->andReturn(['tool' => ['tool_class' => 'X', 'tool_name' => 'x']]);
        // auto_approve=true → default_requires_approval=0
        $toolSettings->shouldReceive('patchOperationOverride')
            ->once()
            ->with($callingId, $ownerId, 'Spora\\Tools\\TimeTool', 'now', [
                'enabled'                   => 1,
                'default_requires_approval' => 0,
            ])
            ->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    'operations' => [['name' => 'now', 'auto_approve' => true]],
                ]],
            ],
            $callingId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
    });
});

describe('AgentTool::execute — read_agent', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'read_agent', 'agent_id' => 7],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('read_agent without agent_id falls through to the calling agent', function (): void {
        // `read_agent` without agent_id is the live replacement for
        // `read_agent_configuration` — same in-place semantics. The
        // orchestrator passes the calling agent id as the second
        // argument; this test mirrors that contract.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'read-agent-self@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $callingId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Calling',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $result = $tool->execute(
            ['action' => 'read_agent'],
            $callingId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['agent_id'])->toBe($callingId)
            ->and($data['name'])->toBe('Calling');
    });

    test('returns the canonical manifest by agent_id', function (): void {
        // Real DB so the Agent::query() lookup in resolveReadAgentTarget
        // has something to find. Boot auth + create the agent row.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'read-agent-owner@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        // The AgentManifest path runs after resolveReadAgentTarget — drive
        // it to an empty manifest so the test stays focused on the
        // dispatch + resource shape, not the tools subsystem.
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $agentId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Alpha',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $result = $tool->execute(['action' => 'read_agent', 'agent_id' => $agentId], 7, $ownerId);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        // Manifest shape (agent_id, name, tools[]), not the legacy
        // AgentResource shape (id, enabled_tools).
        expect($data['agent_id'])->toBe($agentId)
            ->and($data['name'])->toBe('Alpha')
            ->and($data)->toHaveKey('tools')
            ->and($data)->toHaveKey('missing_required')
            ->and($data)->toHaveKey('warnings');
        // result_content is the Markdown wrapper — preamble + two JSON blocks.
        expect($result->content)
            ->toContain("## Agent #{$agentId} \u{2014} Alpha")
            ->toContain('### Base config')
            ->toContain('### Tool config');
    });

    test('does not return another user\'s agent', function (): void {
        $auth     = bootAuthLayer();
        $ownerId  = bootAuth($auth, 'read-agent-cross-owner@example.com');
        $otherId  = bootAuth($auth, 'read-agent-cross-other@example.com');

        [$tool] = makeAgentTool();

        $agentId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId),
            'name'                 => 'Owned',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        // The cross-user read returns "Agent not found or not owned by this user."
        // — never the underlying agent's payload.
        $result = $tool->execute(['action' => 'read_agent', 'agent_id' => $agentId], 7, $otherId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });

    test('rejects template_id identifier (runtime identity is agent_id, not template_id)', function (): void {
        // `template_id` is a creation label, not an identity. Sending it
        // to read_agent surfaces a "this is no longer an identifier"
        // error so the LLM doesn't loop on a payload that will never
        // resolve.
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'read_agent', 'template_id' => 'weather-agent'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`template_id` is no longer an identifier')
            ->and($result->content)->toContain('use the numeric `agent_id`');
    });

    test('read_agent with agent_id=0 fails fast', function (): void {
        // `agent_id` must be a positive integer — zero falls through to
        // the "must be a positive integer" failure rather than being
        // silently mis-routed to the calling agent.
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'read_agent', 'agent_id' => 0],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`agent_id` must be a positive integer');
    });
});

describe('AgentTool::execute — configure_tools (agent_id scoped)', function (): void {
    // Configure_tools gained an optional `agent_id` parameter: omitted → calling
    // agent; supplied → that agent (user-scoped). Task #46 trace exposed the
    // bug where the omitted form silently operated on the calling agent and
    // left a freshly-created agent #6 with zero tools.

    test('configures the targeted agent, not the caller', function (): void {
        // Two distinct agents owned by the same user. configure_tools
        // (agent_id: $target) must apply to $target, not the caller.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ct-targeted@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $targetId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Target',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')
            ->once()
            ->with($targetId, $ownerId, 'Spora\\Tools\\TimeTool')
            ->andReturn(['tool' => ['tool_class' => 'X', 'tool_name' => 'x']]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action'   => 'configure_tools',
                'agent_id' => $targetId,
                'tools'    => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true, 'operations' => []]],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        // The manifest emitted by configure_tools is the targeted agent's,
        // not the caller's.
        expect($data['agent_id'])->toBe($targetId);
    });

    test('refuses to configure another user\'s agent', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ct-cross-owner@example.com');
        $otherId = bootAuth($auth, 'ct-cross-other@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $otherAgent = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Owned',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldNotReceive('enableTool');

        $result = $tool->execute(
            [
                'action'   => 'update_agent',
                'agent_id' => $otherAgent,
                'agent'    => ['name' => 'Hacked'],
            ],
            7,
            $otherId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });

    test('rejects template_id identifier on configure_tools', function (): void {
        // `template_id` was an old identifier on read_agent and never made
        // sense as a configure_tools target. The resolver refuses it
        // explicitly so the LLM knows to re-send with a numeric pk.
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            [
                'action'      => 'configure_tools',
                'template_id' => 'weather-agent',
                'tools'       => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`template_id` is no longer an identifier')
            ->and($result->content)->toContain('use the numeric `agent_id`');
    });
});

test('create_agent validation errors append the agent-creation skill pointer', function (): void {
    // Each create_agent failure path appends a single-line pointer at the
    // agent-creation skill so the LLM knows where to find the schema on the
    // next attempt instead of retrying the same broken payload.
    [$tool, $service] = makeAgentTool();
    /** @var AgentServiceInterface&MockInterface $service */
    $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));

    // Deliberately broken: legacy wrapper shape (top-level `name` inside
    // `agent{}`) trips the explicit "do not wrap in agent{}" branch.
    $result = $tool->execute(
        [
            'action'  => 'create_agent',
            'payload' => [
                'name'  => 'Broken',
                'agent' => ['description' => 'wrong-location'],
            ],
        ],
        7,
        null,
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('agent-creation')
        ->and($result->content)->toContain('skill action: read');
});

test('AgentTool operation descriptions mention the agent-creation skill on relevant operations', function (): void {
    // The skill pointer is only useful if it reaches the LLM-facing schema.
    // Reflect over the class-level #[ToolOperation] attributes and assert
    // each operation that the skill covers carries a pointer to it.
    $reflection = new ReflectionClass(AgentTool::class);
    $byName = [];
    foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
        /** @var ToolOperation $op */
        $op = $attr->newInstance();
        $byName[$op->name] = $op->description;
    }

    expect($byName)->toHaveKey('update_agent')
        ->and($byName)->toHaveKey('create_agent')
        ->and($byName)->toHaveKey('configure_tools')
        ->and($byName)->toHaveKey('read_agent')
        ->and($byName)->toHaveKey('get_available_tools')
        ->and($byName['update_agent'])->toContain('agent-creation')
        ->and($byName['create_agent'])->toContain('agent-creation')
        ->and($byName['configure_tools'])->toContain('agent-creation')
        ->and($byName['read_agent'])->toContain('agent-creation')
        ->and($byName['get_available_tools'])->toContain('agent-creation');
});

test('AgentTool schema narrows action-required params per operation', function (): void {
    // The four #[ToolParameter] declarations each carry a `required: [...]`
    // list of operation names. OperationSchemaFilter narrows the schema's
    // `required[]` to the operations the agent can actually invoke, and
    // strips the side channel before the schema reaches the LLM.
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    // create_agent only → payload is required, agent is not.
    $createOnly = OperationSchemaFilter::filter($schema, ['create_agent'], 'action');
    expect($createOnly['required'])->toContain('payload')
        ->and($createOnly['required'])->not->toContain('agent')
        ->and($createOnly)->not->toHaveKey('__required_when');

    // update_agent only → agent is required, payload is not.
    $updateOnly = OperationSchemaFilter::filter($schema, ['update_agent'], 'action');
    expect($updateOnly['required'])->toContain('agent')
        ->and($updateOnly['required'])->not->toContain('payload');
});

test('update_agent silently drops unknown keys (confirmed via read_agent_configuration)', function (): void {
    // Two-part pin: (1) the AgentTool layer forwards `notes` stripping
    // (`unset($patch['notes'])` in writeConfiguration), and (2) the
    // resulting read_agent response is the canonical
    // EDITABLE_AGENT_FIELDS allowlist — unknown keys never surface back.
    // Covers the "notes vs. config" gotcha called out in the
    // agent-creation skill's `update_agent` workflow section.
    [$tool, $service, $toolSettings] = makeAgentTool();
    /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
    /** @var AgentServiceInterface&MockInterface $service */

    // AgentTool strips `notes` defensively; `name` and `system_prompt`
    // pass through; `foo` and `enable_tools` flow through to the service
    // (which is what actually drops them against the allowlist).
    $agent = new Agent();
    $agent->id = 7;
    $agent->principal_id = createUserPrincipalPublic(99);
    $agent->name = 'New Name';
    $agent->notes = 'pre-existing notes';
    $agent->system_prompt = 'updated';

    $service->shouldReceive('updateAgentByAgentId')
        ->once()
        ->with(
            7,
            Mockery::on(static function (array $patch): bool {
                // `notes` is stripped by the tool; the rest passes through.
                // The AgentService then enforces the EDITABLE_AGENT_FIELDS
                // allowlist, so the unknown keys are dropped downstream.
                return !array_key_exists('notes', $patch)
                    && ($patch['name'] ?? null) === 'New Name'
                    && ($patch['system_prompt'] ?? null) === 'updated';
            }),
        )
        ->andReturn($agent);
    $service->allows('getAgentByAgentId')->andReturn($agent);

    // Manifest render needs per-tool status + per-op state. Empty lists
    // are fine for this test — it asserts which keys SURVIVE in the
    // response, not which tools are configured.
    $toolSettings->allows('getAllToolsStatus')->andReturn([]);
    $toolSettings->allows('getToolsOperations')->andReturn([]);

    $writeResult = $tool->execute(
        [
            'action' => 'update_agent',
            'agent'  => [
                'name'         => 'New Name',
                'system_prompt' => 'updated',
                'foo'          => 'bar',
                'enable_tools' => ['time'],
                'notes'        => 'should be stripped',
            ],
        ],
        7,
        99,
    );

    expect($writeResult->success)->toBeTrue();

    // Confirm via read_agent(self): the manifest only emits the
    // canonical allowlist (base-config + tool-config blocks), so unknown
    // keys never appear in the LLM's confirmation loop. The `notes`
    // strip also held — the field on the returned manifest is the
    // pre-existing value, not the sneaky patch.
    //
    // Real DB so read_agent(self) can resolve the calling-agent row.
    // The $service mock above only stubs the write path; the read
    // path goes through Agent::query() on the live connection.
    $auth    = bootAuthLayer();
    $ownerId = bootAuth($auth, 'write-then-read@example.com');
    Illuminate\Database\Capsule\Manager::table('agents')->insert([
        'id'                  => 7,
        'principal_id' => createUserPrincipalPublic($ownerId),
        'name'                 => 'New Name',
        'description'          => 'pre-existing',
        'system_prompt'        => 'updated',
        'notes'                => 'pre-existing notes',
        'max_steps'            => 10,
        'allow_followup'       => 1,
        'retry_after_minutes'  => 0,
        'max_retries'          => 0,
        'is_active'            => 1,
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
    $readResult = $tool->execute(['action' => 'read_agent'], 7, $ownerId);
    expect($readResult->success)->toBeTrue();
    /** @var array<string, mixed> $readData */
    $readData = $readResult->data;
    expect($readData)->not->toHaveKey('foo')
        ->and($readData)->not->toHaveKey('enable_tools')
        ->and($readData['name'])->toBe('New Name')
        ->and($readData['system_prompt'])->toBe('updated')
        ->and($readData['notes'])->toBe('pre-existing notes');
});

describe('AgentTool::execute — configure_tools {item: [...]} unwrap', function (): void {
    // The OpenAI assistant tool-call channel (and some other providers)
    // can serialize a single-element array as `{item: [...]}` instead
    // of `[...]`. This is a known quirk — see unwrapSingleItemArray().
    // Both the outer `tools` array and each per-tool `operations` list
    // get the same defensive treatment. The unwrap fires ONLY when the
    // input is unambiguously the wrap (a non-list assoc array with the
    // single key `item`); multi-key objects pass through untouched so
    // the usual validation message surfaces.

    test('outer `tools` wrapped as {item: [...]} succeeds and lands on the targeted agent', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'cfg-unwrap-outer@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $targetId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Target',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')
            ->once()
            ->with($targetId, $ownerId, 'Spora\\Tools\\TimeTool')
            ->andReturn(['tool' => ['tool_class' => 'X', 'tool_name' => 'x']]);
        $toolSettings->shouldReceive('patchOperationOverride')->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $entry = [
            'tool_class' => 'Spora\\Tools\\TimeTool',
            'enabled'    => true,
            'operations' => [['name' => 'now', 'enabled' => true]],
        ];
        $result = $tool->execute(
            [
                'action'   => 'configure_tools',
                'agent_id' => $targetId,
                // Outer single-element array wrapped as `{item: [...]}`.
                'tools'    => ['item' => [$entry]],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue(
            'unwrap should land the tool on agent_id when outer is wrapped; got: ' . $result->content,
        );
    });

    test('inner `operations` wrapped as {item: [...]} succeeds', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'cfg-unwrap-inner@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $targetId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Target',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')->once();
        $toolSettings->shouldReceive('patchOperationOverride')
            ->once()
            ->with($targetId, $ownerId, 'Spora\\Tools\\TimeTool', 'now', [
                'enabled'                   => 1,
                'default_requires_approval' => 0,
            ])
            ->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action'   => 'configure_tools',
                'agent_id' => $targetId,
                'tools'    => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    // Inner array for `operations` wrapped as `{item: [...]}`.
                    'operations' => ['item' => [['name' => 'now', 'auto_approve' => true]]],
                ]],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue(
            'inner unwrap should land the op override; got: ' . $result->content,
        );
    });

    test('inner `operations` wrapped as {item: [...]} works on the calling-agent path too', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'cfg-unwrap-self@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')->once();
        $toolSettings->allows('patchOperationOverride')->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                // No agent_id → calling-agent fallback.
                'tools'  => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    'operations' => ['item' => [['name' => 'now']]],
                ]],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
    });

    test('multikey object {item: ..., other: ...} is left alone — not silently unwrapped', function (): void {
        // The unwrap is gated to single-key `{item: ...}` containers. A
        // multi-key object is left as-is so the validator surfaces the
        // usual "must be an array" message rather than silently mutating
        // shape — never auto-correct on legitimate payloads.
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => ['item' => ['enabled' => true], 'foo' => 'bar'],
            ],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('`tools` must be an array');
    });

    test('plain (non-quirk) payloads are still validated the same way', function (): void {
        // Smoke test: the unwrap doesn't change behavior for the
        // canonical shape — legitimate loads survive untouched.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'cfg-plain@example.com');

        [$tool, , $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $toolSettings->shouldReceive('enableTool')->once();
        $toolSettings->allows('patchOperationOverride')->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [
                    [
                        'tool_class' => 'Spora\\Tools\\TimeTool',
                        'enabled'    => true,
                        'operations' => [['name' => 'now', 'enabled' => true]],
                    ],
                ],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
    });
});

describe('AgentTool::execute — create_agent {item: [...]} unwrap', function (): void {
    // Same single-key {item: ...} quirk surfaces in create_agent's
    // `required_plugins` field — same defensive unwrap as
    // configure_tools. The unwrap keeps the validation message
    // accurate: `required_plugins` is rejected because the slim
    // payload doesn't store it, not because the value was malformed.

    test('rejects {item: [...]} required_plugins with the reserved message', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(stubManifestAgent(id: 7));
        $service->shouldNotReceive('createAgent');

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'name'             => 'X',
                    // OpenAI tool-call channel wraps single-element array
                    // as `{item: [...]}`. The unwrap catches the wrap so
                    // the validation message names the right cause
                    // (the slim payload rejects required_plugins),
                    // not a malformed-value complaint.
                    'required_plugins' => ['item' => ['weather', 'calendar']],
                ],
            ],
            7,
            null,
        );

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('`required_plugins` is reserved for the operator-upload endpoint')
            ->and($out->content)->not->toContain('must be an array of strings');
    });
});

describe('AgentTool::execute — update_agent (agent_id scoped)', function (): void {
    // Issue B in the PR #170 review: update_agent routes agent_id to
    // the target agent (with the same user-scoping as read_agent /
    // configure_tools). Omitting agent_id falls through to the calling
    // agent.

    test('agent_id routes the patch to the targeted agent (not the caller)', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'wc-target@example.com');

        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */

        $callerId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Caller',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $targetId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Target',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // `updateAgentByAgentId` is called with the TARGET id, not the caller.
        $updated = stubManifestAgent($targetId, 'Renamed');
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with($targetId, Mockery::on(static fn(array $p) => ($p['name'] ?? null) === 'Renamed'))
            ->andReturn($updated);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action'   => 'update_agent',
                'agent_id' => $targetId,
                'agent'    => ['name' => 'Renamed'],
            ],
            $callerId,
            $ownerId,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['agent_id'])->toBe($targetId)
            ->and($data['name'])->toBe('Renamed');
    });

    test('omitted agent_id still falls through to the calling agent (back-compat)', function (): void {
        // Omitting agent_id means "patch the executor's caller" — kept
        // as the default so existing prompts keep working. The resolve
        // helper short-circuits on missing key, so no DB lookup is
        // required here.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, Mockery::type('array'))
            ->andReturn(stubManifestAgent(7, 'Renamed'));
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'update_agent',
                'agent'  => ['description' => 'patched'],
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
    });


    describe('AgentTool::execute — list_agents (discovery surface)', function (): void {
        test('returns an empty list when the agent service has no rows', function (): void {
            [$tool, $service] = makeAgentTool();
            /** @var AgentServiceInterface&MockInterface $service */
            $agent = new Agent();
            $agent->id = 7;
            $agent->principal_id = createUserPrincipalPublic(99);
            $service->allows('getAgentByAgentId')->andReturn($agent);
            $service->shouldReceive('getAgentsForUser')
                ->once()
                ->with(99)
                ->andReturn([]);

            // The handler sources user_id from the calling Agent, not
            // from the dispatcher param — pass null to make the
            // "user_id comes from the agent" invariant explicit.
            $result = $tool->execute(['action' => 'list_agents'], 7, null);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('No agents')
                ->and($result->data)->toBe(['agents' => []]);
        });

        test('returns a slim id/name/description list of every owned agent', function (): void {
            [$tool, $service] = makeAgentTool();
            /** @var AgentServiceInterface&MockInterface $service */
            $agent = new Agent();
            $agent->id = 7;
            $agent->principal_id = createUserPrincipalPublic(99);
            $service->allows('getAgentByAgentId')->andReturn($agent);
            // Mirrors the AgentResource shape produced by AgentService::agentResource,
            // minus the rest. The handler strips unrelated keys before exposing the
            // payload — cheaper to test against the exact upstream shape than to
            // rely on AgentResource's contract.
            $service->shouldReceive('getAgentsForUser')
                ->once()
                ->with(99)
                ->andReturn([
                    ['id' => 4,  'name' => 'Custom Agent', 'description' => 'does X'],
                    ['id' => 7,  'name' => 'Wetter-Agent',  'description' => null],
                    ['id' => 11, 'name' => 'Travel',        'description' => ''],
                ]);

            $result = $tool->execute(['action' => 'list_agents'], 7, null);

            expect($result->success)->toBeTrue()
                ->and($result->data['agents'])->toBe([
                    ['agent_id' => 4,  'name' => 'Custom Agent', 'description' => 'does X'],
                    ['agent_id' => 7,  'name' => 'Wetter-Agent',  'description' => null],
                    ['agent_id' => 11, 'name' => 'Travel',        'description' => ''],
                ])
                ->and($result->content)->toContain('#4 Custom Agent — does X')
                ->and($result->content)->toContain('#7 Wetter-Agent')
                ->and($result->content)->toContain('#11 Travel');
        });

        test('returns AGENT_NOT_FOUND when the calling agent cannot be resolved', function (): void {
            // The executor's calling-agent lookup failed (e.g. agent
            // was deleted mid-task). Fail-closed at the type system:
            // the dispatcher has no way to forward user_id anyway,
            // and surfacing the resolution error matches the contract
            // used by read_agent / configure_tools.
            [$tool, $service] = makeAgentTool();
            /** @var AgentServiceInterface&MockInterface $service */
            $service->allows('getAgentByAgentId')->andReturn(null);
            $service->shouldNotReceive('getAgentsForUser');

            $result = $tool->execute(['action' => 'list_agents'], 7, null);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Agent not found');
        });

        test('does not leak system_prompt / cell_id / tools from the upstream AgentResource', function (): void {
            // list_agents is the LLM's cheap discovery surface, not a
            // configuration read. system_prompt can be long; cell_id, max_steps,
            // and the agent's toolset were never asked for and would only make
            // the LLM context window bigger. Pin the slim shape so an upstream
            // AgentResource change doesn't silently leak.
            [$tool, $service] = makeAgentTool();
            /** @var AgentServiceInterface&MockInterface $service */
            $agent = new Agent();
            $agent->id = 7;
            $agent->principal_id = createUserPrincipalPublic(99);
            $service->allows('getAgentByAgentId')->andReturn($agent);
            $service->shouldReceive('getAgentsForUser')
                ->once()
                ->andReturn([[
                    'id'             => 4,
                    'name'           => 'Custom Agent',
                    'description'    => 'does X',
                    'system_prompt'  => 'very long system prompt hidden from discovery',
                    'cell_id'        => 'agent-custom',
                    'max_steps'      => 10,
                    'tools'          => ['send_email'],
                    'is_pinned'      => true,
                ]]);

            $result = $tool->execute(['action' => 'list_agents'], 7, null);
            /** @var array{agents: array<int, array<string, mixed>>} $data */
            $data = $result->data;
            $row  = $data['agents'][0];

            expect(array_keys($row))->toBe(['agent_id', 'name', 'description']);
        });
    });

    test('cross-user agent_id returns "not found or not owned"', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'wc-owner@example.com');
        $otherId = bootAuth($auth, 'wc-other@example.com');

        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */

        $otherAgent = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Owned',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            [
                'action'   => 'update_agent',
                'agent_id' => $otherAgent,
                'agent'    => ['name' => 'Hacked'],
            ],
            7,
            $otherId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });
});

describe('AgentTool::execute — update_agent (canonical)', function (): void {
    // `update_agent` is the only config-write operation on the
    // `agent` tool.

    test('patches the targeted agent and returns the manifest', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
        /** @var AgentServiceInterface&MockInterface $service */
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['description' => 'updated'])
            ->andReturn(stubManifestAgent(7, 'Alpha'));
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            ['action' => 'update_agent', 'agent' => ['description' => 'updated']],
            7,
        );

        expect($result->success)->toBeTrue()
            ->and($result->content)->not->toContain('deprecated')
            ->and($result->data['name'])->toBe('Alpha');
    });

    test('cross-user agent_id returns "not found or not owned"', function (): void {
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'red-owner@example.com');
        $otherId = bootAuth($auth, 'red-other@example.com');
        $otherAgent = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'principal_id' => createUserPrincipalPublic($ownerId), 'name' => 'Owned',
            'max_steps' => 10, 'allow_followup' => 1,
            'retry_after_minutes' => 0, 'max_retries' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$tool, $service] = makeAgentTool();
        /** @var AgentServiceInterface&MockInterface $service */
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            [
                'action'   => 'update_agent',
                'agent_id' => $otherAgent,
                'agent'    => ['name' => 'Hacked'],
            ],
            7,
            $otherId,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });
});
