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

use function Tests\Feature\MediaArchive\makeMediaArchiveService;

/**
 * Wire-schema coverage for {@see MediaTool}.
 *
 * Locks in the contract the orchestrator relies on:
 *
 *   - `search`         : enabled_by_default = true,  requires_approval_by_default = false
 *   - `get_media`      : enabled_by_default = true,  requires_approval_by_default = false
 *   - `get_public_url` : enabled_by_default = false, requires_approval_by_default = true
 *   - `get_embed_code` : enabled_by_default = true,  requires_approval_by_default = false
 *   - The discriminator `enum` in the generated JSON schema lists all four
 *     operations (the orchestrator narrows the enum per-agent).
 *   - When no per-agent override exists, `get_public_url` is filtered out of
 *     the tool list — this matches `enabledByDefault: false` behavior in
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
        'user_id'   => $userId,
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

function buildMediaToolForSchema(): MediaTool
{
    // Build a real archive service so the constructor's type hint is
    // satisfied. None of these tests invoke execute().
    $ctx = makeMediaArchiveService();
    $auth = Mockery::mock(Spora\Auth\AuthService::class);
    $auth->allows('isAdmin')->andReturn(false);
    $auth->allows('currentUserId')->andReturn(null);

    return new MediaTool($ctx['service'], $auth);
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

    it('declares exactly the four expected operations', function (): void {
        $names = array_map(static fn(ToolOperation $op) => $op->name, mediaToolOperations());
        expect($names)->toBe(['search', 'get_media', 'get_public_url', 'get_embed_code']);
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

    it('marks get_public_url as hidden by default and requiring approval', function (): void {
        $op = mediaToolOpByName('get_public_url');
        expect($op->enabledByDefault)->toBeFalse()
            ->and($op->requiresApprovalByDefault)->toBeTrue();
    });

    it('marks get_embed_code as enabled by default and auto-approved', function (): void {
        $op = mediaToolOpByName('get_embed_code');
        expect($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse();
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
                'agent' => 'Only media created by this agent',
                'user'  => 'All media owned by the current user (across agents)',
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
    it('synthesizes an "action" discriminator with the four operations in its enum', function (): void {
        $tool = buildMediaToolForSchema();

        $schema = $tool->getParametersSchema();
        expect($schema['type'])->toBe('object');
        expect($schema['properties'])->toHaveKey('action');
        expect($schema['properties']['action']['type'])->toBe('string');
        expect($schema['properties']['action']['enum'])->toBe(['search', 'get_media', 'get_public_url', 'get_embed_code']);
    });
});

describe('MediaTool wiring via ToolDefinitionBuilder', function (): void {
    it('omits get_public_url from the tool list when no per-agent override exists', function (): void {
        // No AgentToolOperationOverride rows for this agent — the orchestrator's
        // ToolDefinitionBuilder should hide `get_public_url` (enabledByDefault=false)
        // and emit only the default-enabled operations: `search`, `get_media`,
        // and `get_embed_code`.
        $toolInstance = buildMediaToolForSchema();

        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: 999_001, userId: null);

        expect($defs)->toHaveCount(1);
        expect($defs[0]['function']['name'])->toBe('media');

        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->toBe(['search', 'get_media', 'get_embed_code']);
        expect($enum)->not->toContain('get_public_url');
    });

    it('includes get_public_url when a per-agent override opts the operation in', function (): void {
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
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: $agentId, userId: null);

        expect($defs)->toHaveCount(1);
        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->toBe(['search', 'get_media', 'get_public_url', 'get_embed_code']);
    });

    it('excludes the tool entirely when the agent does not have it enabled', function (): void {
        $toolInstance = buildMediaToolForSchema();

        $builder = new ToolDefinitionBuilder([$toolInstance], null, null);
        $defs    = $builder->buildToolDefinitions(enabledClasses: [], agentId: 999_003, userId: null);

        expect($defs)->toBe([]);
    });
});
