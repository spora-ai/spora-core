<?php

declare(strict_types=1);

use Spora\Agents\ToolDefinitionBuilder;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaTool;

// makeMediaArchiveService() is autoloaded globally via composer.json
// (autoload-dev.files -> tests/Support/CrossFileTestHelpers.php).

/**
 * Wire-schema coverage for {@see MediaTool}.
 *
 * Locks in the contract the orchestrator relies on:
 *
 *   - `search`            : enabled_by_default = true,  requires_approval_by_default = false
 *   - `get_media`         : enabled_by_default = true,  requires_approval_by_default = false
 *   - `get_public_url`    : enabled_by_default = true,  requires_approval_by_default = true
 *   - `get_embed_code`    : enabled_by_default = true,  requires_approval_by_default = false
 *   - `get_source`        : enabled_by_default = true,  requires_approval_by_default = false
 *   - `list_derivatives`  : enabled_by_default = true,  requires_approval_by_default = false
 *   - `create_derivative` : enabled_by_default = true,  requires_approval_by_default = false
 *   - `create_media`      : enabled_by_default = true,  requires_approval_by_default = false
 *   - The discriminator `enum` in the generated JSON schema lists all eight
 *     operations (the orchestrator narrows the enum per-agent).
 *   - All eight are enabled by default, so an agent with no override row sees
 *     the full matrix. `get_public_url` is the sole operation that asks for
 *     approval: it is the one that mints a link that outlives the session, so
 *     every call is put in front of the operator. The remaining seven are
 *     reads or writes the agent could already make against assets it owns.
 *     Matches `enabledByDefault` in
 *     {@see ToolDefinitionBuilder::buildToolDefinitions()}.
 *
 * Uses a real `MediaArchiveService` rather than a Mockery mock because
 * MediaArchiveService is `final` and the ToolDefinitionBuilder + tool
 * constructor only need to hold a reference — neither execute() nor the
 * schema builders are exercised here.
 */

/**
 * Create the underlying agent row + user row the AgentToolOperationOverride
 * FK depends on. The override row itself is what we care about for the test.
 */
function seedMediaToolApprovalAgent(): int
{
    // Insert a user row first to satisfy the agents.user_id FK.
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('mediatool-approval-%s-%s@example.com', bin2hex(random_bytes(4)), microtime(true));
    $now   = time();
    $stmt->execute([
        $email,
        password_hash('Password1!', PASSWORD_BCRYPT),
        $email,
        $now,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now),
    ]);
    $userId = (int) $pdo->lastInsertId();

    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'MediaTool approval agent',
        'is_active' => true,
    ])->id;
}

function mediaToolOperations(): array
{
    $ref = new ReflectionClass(MediaTool::class);
    return array_map(
        static fn(ReflectionAttribute $a) => $a->newInstance(),
        $ref->getAttributes(ToolOperation::class),
    );
}

function mediaToolOpByName(string $name): ToolOperation
{
    foreach (mediaToolOperations() as $op) {
        if ($op->name === $name) {
            return $op;
        }
    }
    throw new RuntimeException("MediaTool has no #[ToolOperation] named {$name}");
}

describe('MediaTool attributes', function (): void {
    it('declares the "media" tool name and a description', function (): void {
        $ref = new ReflectionClass(MediaTool::class);
        $attrs = $ref->getAttributes(Tool::class);
        expect($attrs)->toHaveCount(1);

        $tool = $attrs[0]->newInstance();
        expect($tool->name)->toBe('media');
        expect($tool->displayName)->toBe('Media Library');
        expect($tool->description)->toContain('media library');
    });

    it('declares exactly the eight expected operations', function (): void {
        $names = array_map(static fn(ToolOperation $op) => $op->name, mediaToolOperations());
        expect($names)->toBe(['search', 'get_media', 'get_public_url', 'get_embed_code', 'get_source', 'list_derivatives', 'create_derivative', 'create_media']);
    });

    it('marks search as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('search');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks get_media as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('get_media');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks get_public_url as enabled by default and requiring approval', function (): void {
        $op = mediaToolOpByName('get_public_url');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeTrue();
    });

    it('marks get_embed_code as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('get_embed_code');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks get_source as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('get_source');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks list_derivatives as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('list_derivatives');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks create_derivative as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('create_derivative');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('marks create_media as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('create_media');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
    });

    it('gates on approval for get_public_url alone', function (): void {
        // The invariant behind the per-op assertions above, stated once so a
        // future operation cannot quietly join the approval set: enabling an op
        // is cheap to undo from the dashboard, but an approval prompt nobody
        // expects is a stall on every turn. So exactly one operation may ask,
        // and it has to be the one that reaches outside the session.
        $ops = mediaToolOperations();

        $approving = array_values(array_map(
            static fn(ToolOperation $op): string => $op->name,
            array_filter($ops, static fn(ToolOperation $op): bool => $op->requiresApprovalByDefault),
        ));

        expect($approving)->toBe(['get_public_url']);
    });

    it('enables every operation by default', function (): void {
        $disabled = array_values(array_map(
            static fn(ToolOperation $op): string => $op->name,
            array_filter(
                mediaToolOperations(),
                static fn(ToolOperation $op): bool => !$op->enabledByDefault,
            ),
        ));

        expect($disabled)->toBe([]);
    });

    it('exposes the scope setting as a select with two options', function (): void {
        $ref = new ReflectionClass(MediaTool::class);
        $attrs = $ref->getAttributes(ToolSetting::class);

        expect($attrs)->toHaveCount(1);
        $setting = $attrs[0]->newInstance();
        expect($setting->key)->toBe('scope')
            ->and($setting->type)->toBe('select')
            ->and($setting->default)->toBe('agent')
            ->and($setting->options)->toBe([
                'agent'     => 'Only media created by this agent',
                'principal' => 'All media owned by the calling agent\'s principal '
                             . '(direct uploads + every agent of the principal)',
            ]);
    });

    it('declares the expected parameter names', function (): void {
        $ref = new ReflectionClass(MediaTool::class);
        $attrs = $ref->getAttributes(ToolParameter::class);
        $names = array_map(static fn(ReflectionAttribute $a) => $a->newInstance()->name, $attrs);

        expect($names)->toContain('asset_id', 'plugin_slug', 'mime_type', 'task_id', 'limit', 'offset');
    });
});

describe('MediaTool parameter schema', function (): void {
    it('synthesizes an "action" discriminator with the eight operations in its enum', function (): void {
        $tool = buildMediaToolForSchema();

        $schema = $tool->getParametersSchema();
        expect($schema['type'])->toBe('object');
        expect($schema['properties'])->toHaveKey('action');
        expect($schema['properties']['action']['type'])->toBe('string');
        expect($schema['properties']['action']['enum'])->toBe(['search', 'get_media', 'get_public_url', 'get_embed_code', 'get_source', 'list_derivatives', 'create_derivative', 'create_media']);
    });
});

describe('MediaTool wiring via ToolDefinitionBuilder', function (): void {
    it('exposes all eight operations when no per-agent override exists', function (): void {
        // No AgentToolOperationOverride rows for this agent. Every op declares
        // `enabledByDefault: true`, so the full matrix is exposed — including
        // `get_public_url`, which is exposed but still asks for approval.
        //
        // That split is the point: the enum is what the model may *ask* for,
        // and approval is decided per call at execute time. Narrowing the enum
        // by approval would make a setting the operator can change mid-task
        // silently rewrite the tool contract the model was already given.
        $toolInstance = buildMediaToolForSchema();

        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: 999_001, context: null);

        expect($defs)->toHaveCount(1);
        expect($defs[0]['function']['name'])->toBe('media');

        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->toBe([
            'search',
            'get_media',
            'get_public_url',
            'get_embed_code',
            'get_source',
            'list_derivatives',
            'create_derivative',
            'create_media',
        ]);
    });

    it('narrows the enum when a per-agent override disables one operation', function (): void {
        // The other direction still works: an explicit per-agent `enabled = 0`
        // removes the op from the enum even though it is on by default. This is
        // the escape hatch for an operator who wants the tool without one of
        // its operations, without a code change.
        $agentId = seedMediaToolApprovalAgent();

        AgentToolOperationOverride::create([
            'agent_id'                  => $agentId,
            'tool_class'                => MediaTool::class,
            'operation'                 => 'get_source',
            'enabled'                   => 0,
            'default_requires_approval' => 0,
        ]);

        $toolInstance = buildMediaToolForSchema();
        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: $agentId, context: null);

        expect($defs)->toHaveCount(1);
        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->not->toContain('get_source');
        expect($enum)->toContain('get_public_url', 'create_media');
    });

    it('keeps get_public_url in the enum while a per-agent override still requires approval', function (): void {
        $agentId = seedMediaToolApprovalAgent();

        AgentToolOperationOverride::create([
            'agent_id'                  => $agentId,
            'tool_class'                => MediaTool::class,
            'operation'                 => 'get_public_url',
            'enabled'                   => 1,
            'default_requires_approval' => 1,
        ]);

        $toolInstance = buildMediaToolForSchema();
        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: $agentId, context: null);

        expect($defs)->toHaveCount(1);
        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        // Declaration order, not filtered order: the override enabled an op
        // that was already on, so the enum comes back complete.
        expect($enum)->toBe([
            'search',
            'get_media',
            'get_public_url',
            'get_embed_code',
            'get_source',
            'list_derivatives',
            'create_derivative',
            'create_media',
        ]);
    });

    it('excludes the tool entirely when the agent does not have it enabled', function (): void {
        $toolInstance = buildMediaToolForSchema();

        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions(enabledClasses: [], agentId: 999_003, context: null);

        expect($defs)->toBe([]);
    });
});
