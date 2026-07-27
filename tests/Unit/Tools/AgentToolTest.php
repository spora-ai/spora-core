<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\AgentTool;

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

    return [
        new AgentTool($agentService, $toolSettings, $importer, $validator),
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

    return [
        new AgentTool($service, $toolSettings, $importer, $validator, $pluginLoader, $iconResolver),
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
    $agent->user_id = 99;
    return $agent;
}

describe('AgentTool::execute — read_agent_configuration', function (): void {
    test('returns the agent resource plus enabled_tools', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent        = new Agent();
        $agent->id    = 7;
        $agent->name  = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            ['tool_class' => 'Foo', 'tool_name' => 'foo', 'is_enabled' => true, 'can_enable' => true, 'missing_required' => []],
        ]);

        $result = $tool->execute(['action' => 'read_agent_configuration'], 7, 99);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['id'])->toBe(7)
            ->and($data['name'])->toBe('Alpha')
            ->and($data['enabled_tools'])->toBe([]);
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'read_agent_configuration'], 999);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found');
    });
});

describe('AgentTool::execute — write_agent_configuration', function (): void {
    test('forwards patch through AgentServiceInterface::updateAgentByAgentId', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->andReturn(stubAgent(7, 'Alpha', null));

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['description' => 'updated']],
            7,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['name'])->toBe('Alpha');
    });

    test('silently drops `notes` from the patch (notes are write_notes-only)', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        // `description` survives the strip so the service still gets called.
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['description' => 'x'])
            ->andReturn(stubAgent(7));

        $result = $tool->execute(
            [
                'action' => 'write_agent_configuration',
                'agent'  => ['description' => 'x', 'notes' => 'sneaky'],
            ],
            7,
        );

        expect($result->success)->toBeTrue();
    });

    test('returns failure when the only field in the patch is notes', function (): void {
        // If the LLM only sends notes, the strip leaves the patch empty
        // and we surface a clear failure rather than silently reporting
        // success with no DB write. Operators can use write_notes for that.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            [
                'action' => 'write_agent_configuration',
                'agent'  => ['notes' => 'sneaky'],
            ],
            7,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Use write_notes to mutate notes');
    });

    test('returns failure when the agent object is missing', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'write_agent_configuration'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('agent object is required');
    });
});

describe('AgentTool::execute — write_notes', function (): void {
    test('rejects missing content', function (): void {
        // Agent existence is checked first, so the LLM sees "Agent not
        // found." rather than a content-shape complaint when both are wrong.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'write_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });

    test('rejects missing content when the agent exists', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->user_id = 99;
        $agent->name = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(['action' => 'write_notes'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('content is required');
    });

    test('rejects invalid mode', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->user_id = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'read_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — write_agent_configuration — happy path', function (): void {
    test('forwards patch and returns the updated resource', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->andReturn(stubAgent(7, 'Renamed'));

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['name' => 'Renamed']],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['name'])->toBe('Renamed');
    });

    test('returns failure when the agent disappears mid-write', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        // updateAgentByAgentId returns null when the agent no longer exists,
        // which the tool surfaces as the standard AGENT_NOT_FOUND failure.
        $service->allows('updateAgentByAgentId')->andReturn(null);

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['name' => 'x']],
            7,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — get_available_tools', function (): void {
    test('enriches per-agent status with presenter metadata and returns a versioned JSON payload as content', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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

        // `$content` is the new LLM-facing contract — a compact versioned
        // JSON payload, not a one-liner summary.
        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['version'])->toBe(1)
            ->and($payload['count'])->toBe(1);
        $tool = $payload['tools'][0];
        expect($tool['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($tool['tool_name'])->toBe('calculator')
            ->and($tool['call_name'])->toBe('calculator')
            ->and($tool['description'])->toBeString()
            ->and($tool['enabled'])->toBeFalse()
            ->and($tool['ready_to_enable'])->toBeTrue()
            ->and($tool['missing_required'])->toBe([])
            ->and($tool['source']['kind'])->toBe('core')
            ->and($tool['source']['slug'])->toBeNull();
    });

    test('flags needs_configuration when can_enable is false', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        /** @var MockInterface $iconResolver */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        // We use a real Spora core class as the target so the
        // `class-string<ToolInterface>` return type is satisfied, and the
        // plugin mapping is supplied by a stub plugin injected via
        // reflection (PluginLoader is final and cannot be mocked).
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => Spora\Tools\TimeTool::class,
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
                return [Spora\Tools\TimeTool::class];
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
        expect($row['call_name'])->toBe('tavily:time')
            ->and($row['source']['kind'])->toBe('plugin')
            ->and($row['source']['slug'])->toBe('tavily')
            ->and($row['source']['name'])->toBe('Tavily');
    });

    test('renders per-operation enabled + requires_approval from effective state', function (): void {
        // Use AgentTool as the target: it ships 7 #[ToolOperation] entries
        // with a mix of enabledByDefault / requiresApprovalByDefault flags,
        // and `getToolsOperations` is easy to drive from a mock. The
        // presenter enumerates operations in declaration order, so we
        // expect `read_agent_configuration` first and `write_agent_configuration`
        // second in the response.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
                'operation'                   => 'read_agent_configuration',
                'effective_enabled'           => true,
                'effective_requires_approval' => false,
            ],
            [
                'tool_class'                  => AgentTool::class,
                'operation'                   => 'write_agent_configuration',
                'effective_enabled'           => false,
                'effective_requires_approval' => true,
            ],
        ]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $opsByName = [];
        foreach ($payload['tools'][0]['operations'] as $op) {
            if (in_array($op['name'], ['read_agent_configuration', 'write_agent_configuration'], true)) {
                $opsByName[$op['name']] = $op;
            }
        }
        expect($opsByName['read_agent_configuration']['enabled'])->toBeTrue()
            ->and($opsByName['read_agent_configuration']['requires_approval'])->toBeFalse();
        expect($opsByName['write_agent_configuration']['enabled'])->toBeFalse()
            ->and($opsByName['write_agent_configuration']['requires_approval'])->toBeTrue();
    });

    test('falls back to operation defaults when no per-agent override exists', function (): void {
        // `getToolsOperations` returns rows only for *enabled* tools, so a
        // not-yet-enabled tool will not appear in the effective map at all.
        // The presenter should fall back to the operation's
        // enabledByDefault / requiresApprovalByDefault from #[ToolOperation].
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
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
        // read_agent_configuration: enabledByDefault=true, requiresApprovalByDefault=false
        expect($opsByName['read_agent_configuration']['enabled'])->toBeTrue()
            ->and($opsByName['read_agent_configuration']['requires_approval'])->toBeFalse();
        // write_agent_configuration: enabledByDefault=false, requiresApprovalByDefault=true
        expect($opsByName['write_agent_configuration']['enabled'])->toBeFalse()
            ->and($opsByName['write_agent_configuration']['requires_approval'])->toBeTrue();
    });

    test('returns failure when the agent does not exist', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found');
    });
});

describe('AgentTool::execute — create_agent', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'create_agent', 'payload' => ['id' => 'x']],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('rejects missing payload', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'create_agent'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('payload');
    });

    test('rejects invalid payload via validator', function (): void {
        [$tool] = makeAgentTool();

        // Shape is missing the required top-level keys (id, name, version)
        // so the validator fails before any DB write.
        $out = $tool->execute(
            ['action' => 'create_agent', 'payload' => ['agent' => [], 'tools' => []]],
            7,
            99,
        );

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('payload failed validation');
    });

    test('happy path: validates and imports a complete payload', function (): void {
        // Booting auth so the importer can resolve the agent's user_id FK
        // against the in-memory SQLite db seeded by Pest's beforeEach.
        $auth   = bootAuthLayer();
        $userId = bootAuth($auth, 'creator@example.com');
        [$tool] = makeAgentTool();

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'id'      => 'new-agent',
                    'name'    => 'New Agent',
                    'version' => '1.0.0',
                    'agent'   => [
                        'description' => 'created via AgentTool',
                        'notes'       => 'runbook step 1',
                    ],
                    'tools'   => [],
                ],
            ],
            7,
            $userId,
        );

        expect($out->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $out->data;
        /** @var array<string, mixed> $agentRow */
        $agentRow = $data['agent'];
        expect($agentRow['name'])->toBe('New Agent')
            ->and($agentRow['description'])->toBe('created via AgentTool')
            ->and($agentRow['notes'])->toBe('runbook step 1')
            ->and($data['tools_enabled'])->toBe([]);
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
});
