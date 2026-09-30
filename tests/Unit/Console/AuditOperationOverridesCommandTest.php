<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Console\Commands\AuditOperationOverridesCommand;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Tools\CalculatorTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `spora:audit-operation-overrides` surfaces rows whose `tool_class` no longer
 * resolves. Both orphan sets matter: an override row without its sibling
 * reports itself as governing a tool, and an `agent_tools` row without either
 * is dead weight that no reader can see.
 */
defined('AUDIT_TEST_PASSWORD') || define('AUDIT_TEST_PASSWORD', 'Password1!');

/**
 * Both orphan tables carry an FK to `agents`, so an orphan row needs a real
 * parent agent. The point of the audit is the missing *tool class*, not a
 * missing agent.
 */
function auditAgent(): int
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "audit-{$seq}@example.com", AUDIT_TEST_PASSWORD);

    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Audit Agent ' . $seq,
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;
}

function auditTester(): CommandTester
{
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    return new CommandTester(new AuditOperationOverridesCommand($db));
}

function auditSeedOverride(int $agentId, string $toolClass, string $operation): void
{
    Capsule::table('agent_tool_operation_overrides')->insert([
        'agent_id'  => $agentId,
        'tool_class' => $toolClass,
        'operation' => $operation,
        'enabled'   => 1,
        'default_requires_approval' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function auditSeedAgentTool(int $agentId, string $toolClass): void
{
    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => $toolClass,
        'tool_name'  => 'gone',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

it('reports a clean database and exits successfully', function (): void {
    $tester = auditTester();

    $exit = $tester->execute([]);

    expect($exit)->toBe(Command::SUCCESS);
    expect($tester->getDisplay())->toContain('No orphaned tool rows')
        ->and($tester->getDisplay())->toContain('[agent_tool_operation_overrides] clean')
        ->and($tester->getDisplay())->toContain('[agent_tools] clean');
});

it('reports orphaned override rows and exits with failure', function (): void {
    auditSeedOverride(auditAgent(), 'Spora\\Tools\\RemovedTool', 'run');

    $tester = auditTester();
    $exit = $tester->execute([]);

    $display = $tester->getDisplay();
    expect($exit)->toBe(Command::FAILURE)
        ->and($display)->toContain('[agent_tool_operation_overrides] 1 row(s) reference a missing tool class')
        ->and($display)->toContain('tool_class=Spora\\Tools\\RemovedTool operation=run')
        ->and($display)->toContain('1 orphaned row(s) found');
});

it('reports orphaned agent_tools rows too, not just overrides', function (): void {
    auditSeedAgentTool(auditAgent(), 'Spora\\Tools\\HandoverTool');

    $tester = auditTester();
    $exit = $tester->execute([]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('[agent_tools] 1 row(s) reference a missing tool class')
        // The renamed tool from the real dev database, which is the case the
        // original single-table audit would have missed.
        ->and($tester->getDisplay())->toContain('tool_class=Spora\\Tools\\HandoverTool');
});

it('counts both orphan sets in one run', function (): void {
    $agentId = auditAgent();
    auditSeedOverride($agentId, 'Spora\\Tools\\GoneOne', 'a');
    auditSeedOverride($agentId, 'Spora\\Tools\\GoneOne', 'b');
    auditSeedAgentTool($agentId, 'Spora\\Tools\\GoneTwo');

    $tester = auditTester();
    $exit = $tester->execute([]);

    expect($exit)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('3 orphaned row(s) found');
});

it('does not flag rows whose tool class still resolves', function (): void {
    $agentId = auditAgent();
    auditSeedOverride($agentId, CalculatorTool::class, 'calculate');
    auditSeedAgentTool($agentId, CalculatorTool::class);

    $tester = auditTester();
    $exit = $tester->execute([]);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->not->toContain(CalculatorTool::class);
});

it('makes no writes to either table', function (): void {
    auditSeedOverride(auditAgent(), 'Spora\\Tools\\GoneOne', 'a');
    $before = Capsule::table('agent_tool_operation_overrides')->count();

    auditTester()->execute([]);

    expect(Capsule::table('agent_tool_operation_overrides')->count())->toBe($before);
});
