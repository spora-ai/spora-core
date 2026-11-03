<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;

/**
 * The effective approval default of the media tool, read the way the
 * runtime reads it.
 *
 * `#[ToolOperation(requiresApprovalByDefault:)]` is the *activation-time*
 * default. The configuration is the agent's
 * `agent_tool_operation_overrides` row, and the row wins — in both
 * directions, never clamped toward the attribute. So there are two
 * questions, and they have different answers:
 *
 *   - what does a fresh install do? The attribute, because no row
 *     exists to override it;
 *   - what does an install where an operator configured the agent do?
 *     The row.
 *
 * The skill documents the first. This pins it through the two readers
 * that decide it at runtime — {@see Orchestrator::resolveRequiresApproval()},
 * which gates the call, and {@see AgentToolOperationsResolver}, which
 * answers `read_agent` and `get_available_tools` — so the skill's table
 * and the platform's behaviour cannot drift apart silently.
 */

/**
 * An agent with the media tool enabled and no operation overrides.
 *
 * @return array{0: int, 1: int} the agent id and the owner user id
 */
function mediaApprovalAgent(): array
{
    static $seq = 0;
    $seq++;

    $pdo  = Capsule::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('media-approval-%s-%s@example.com', bin2hex(random_bytes(4)), $seq);
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

    $agentId = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Media approval agent ' . $seq,
        'is_active'    => true,
    ])->id;

    AgentTool::create([
        'agent_id'  => $agentId,
        'tool_class' => MediaTool::class,
        'tool_name'  => 'media',
    ]);

    return [$agentId, $userId];
}

function mediaApprovalResolver(): AgentToolOperationsResolver
{
    $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new Logger('test'), [MediaTool::class]);
    $llmConfig  = new LLMConfigService($security, []);

    return new AgentToolOperationsResolver(
        new AgentToolInstanceResolver(),
        new AgentToolOverrideResolver($toolConfig, $llmConfig, new LLMConfigPreferences()),
    );
}

function mediaApprovalOrchestrator(): Orchestrator
{
    $factory = Mockery::mock(Spora\Drivers\DriverFactory::class);

    return new Orchestrator($factory, new OrchestratorConfig());
}

/**
 * The effective approval requirement of every media operation, read
 * through the resolver `read_agent` and `get_available_tools` use.
 *
 * @return array<string, bool> operation => requires approval
 */
function mediaEffectiveApproval(int $agentId, int $userId): array
{
    $rows = mediaApprovalResolver()->getToolsOperations($agentId, $userId) ?? [];

    $out = [];
    foreach ($rows as $row) {
        if ($row['tool_class'] === MediaTool::class) {
            $out[(string) $row['operation']] = (bool) $row['effective_requires_approval'];
        }
    }

    return $out;
}

it('gates exactly the five durable operations on a fresh install', function (): void {
    [$agentId, $userId] = mediaApprovalAgent();

    // No override row exists: the attribute is the answer.
    expect(AgentToolOperationOverride::where('agent_id', $agentId)->count())->toBe(0);

    $effective = mediaEffectiveApproval($agentId, $userId);

    expect($effective)->toBe([
        'search'            => false,
        'get_media'         => false,
        'get_public_url'    => true,
        'get_embed_code'    => false,
        'get_source'        => false,
        'list_derivatives'  => false,
        'create_derivative' => false,
        'create_media'      => true,
        'list_folders'      => false,
        'create_folder'     => true,
        'rename_folder'     => true,
        'delete_folder'     => true,
        'file_asset'        => false,
        'search_assets'     => false,
    ]);
});

it('gates the same five operations at the call site the orchestrator uses', function (): void {
    // The resolver answers the API; the orchestrator answers the call.
    // They read the same rows, so they must agree — a divergence would
    // let a call present as auto-approved and then wait for an approval
    // nobody was told to expect.
    [$agentId] = mediaApprovalAgent();

    $orchestrator = mediaApprovalOrchestrator();
    $tool        = (new AgentToolInstanceResolver())->resolveToolInstance(MediaTool::class);
    expect($tool)->toBeInstanceOf(MediaTool::class);

    foreach (mediaEffectiveApproval($agentId, 1) as $operation => $requiresApproval) {
        $resolved = $orchestrator->resolveRequiresApproval(
            $tool,
            MediaTool::class,
            $agentId,
            ['action' => $operation],
        );

        expect($resolved)->toBe($requiresApproval, "operation {$operation}");
    }
});

it('lets a stored override change the effective default in either direction', function (): void {
    // The attribute is only the default. An operator who stores a row
    // owns the configuration, so the row is what the agent sees — which
    // is why the skill tells the agent to read the live state rather
    // than trust its table.
    [$agentId, $userId] = mediaApprovalAgent();

    Capsule::table('agent_tool_operation_overrides')->insert([
        'agent_id'                  => $agentId,
        'tool_class'                => MediaTool::class,
        'operation'                 => 'create_media',
        'enabled'                   => null,
        'default_requires_approval' => 0,
        'created_at'                => date('Y-m-d H:i:s'),
        'updated_at'                => date('Y-m-d H:i:s'),
    ]);

    $effective = mediaEffectiveApproval($agentId, $userId);

    expect($effective['create_media'])->toBeFalse()
        // The others keep the attribute's answer.
        ->and($effective['get_public_url'])->toBeTrue()
        ->and($effective['create_folder'])->toBeTrue();
});
