<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Spora\Agents\ApprovedBatchExecutor;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Drivers\DriverFactory;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;

/**
 * Regression coverage for the per-op helper added to {@see ApprovedBatchExecutor}
 * when the SchemaValidator was extended with `?string $operationName`. The
 * resume path needs the operation each pending tool_call was queued for so
 * the runtime validator can narrow per-op `required[]` bindings against it.
 *
 * These tests are deliberately minimal: they exercise the bulk-load helper
 * directly via reflection rather than spinning the full orchestrator wiring
 * needed for an end-to-end resume. The validator behaviour is fully covered in
 * `SchemaValidatorTest` and the synchronous path is covered by the
 * `time(action: "now")` e2e test in `ToolCallExecutorTest`.
 */
final class ApprovedBatchExecutorOperationMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
        $db->boot();
        Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
            Illuminate\Database\Capsule\Manager::connection()->rollBack();
        }
        Database::resetBootState();
        parent::tearDown();
    }

    public function test_returns_provider_call_id_to_operation_map_for_pending_rows(): void
    {
        [$userId, $taskId] = $this->seedTask();

        ToolCallModel::insert([
            [
                'task_id'          => $taskId,
                'agent_id'         => 1,
                'provider_call_id' => 'call_aaa',
                'tool_name'        => 'time',
                'tool_class'       => 'Spora\\Tools\\TimeTool',
                'tool_type'        => 'output',
                'operation'        => 'now',
                'operation_description' => 'Get now',
                'status'           => 'PENDING_APPROVAL',
                'proposed_arguments'   => json_encode(['action' => 'now']),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ],
            [
                'task_id'          => $taskId,
                'agent_id'         => 1,
                'provider_call_id' => 'call_bbb',
                'tool_name'        => 'time',
                'tool_class'       => 'Spora\\Tools\\TimeTool',
                'tool_type'        => 'output',
                'operation'        => 'format',
                'operation_description' => 'Format epoch',
                'status'           => 'PENDING_APPROVAL',
                'proposed_arguments'   => json_encode(['action' => 'format', 'epoch' => 0]),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ],
        ]);

        $executor = $this->makeExecutor();

        $map = $this->callIndexPersistedOperations($executor, $taskId);

        $this->assertSame(
            ['call_aaa' => 'now', 'call_bbb' => 'format'],
            $map,
        );
    }

    public function test_skips_rows_with_empty_or_null_operation(): void
    {
        [$userId, $taskId] = $this->seedTask();

        ToolCallModel::insert([
            [
                'task_id'          => $taskId,
                'agent_id'         => 1,
                'provider_call_id' => 'call_with_op',
                'tool_name'        => 'time',
                'tool_class'       => 'Spora\\Tools\\TimeTool',
                'tool_type'        => 'output',
                'operation'        => 'now',
                'operation_description' => 'ok',
                'status'           => 'PENDING_APPROVAL',
                'proposed_arguments'   => json_encode(['action' => 'now']),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ],
            [
                'task_id'          => $taskId,
                'agent_id'         => 1,
                'provider_call_id' => 'call_legacy',
                'tool_name'        => 'legacy',
                'tool_class'       => 'Legacy\\Tool',
                'tool_type'        => 'output',
                'operation'        => '', // pre-Skills rows may have empty operation
                'operation_description' => '',
                'status'           => 'PENDING_APPROVAL',
                'proposed_arguments'   => json_encode([]),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ],
        ]);

        $executor = $this->makeExecutor();
        $map      = $this->callIndexPersistedOperations($executor, $taskId);

        // Only the row with a real operation shows up — the empty one is
        // silently dropped so older code paths don't accidentally validate
        // with a misnarrowed schema.
        $this->assertSame(['call_with_op' => 'now'], $map);
    }

    public function test_returns_empty_map_when_no_rows_pending(): void
    {
        [$userId, $taskId] = $this->seedTask();

        $executor = $this->makeExecutor();
        $map      = $this->callIndexPersistedOperations($executor, $taskId);

        $this->assertSame([], $map);
    }

    private function callIndexPersistedOperations(ApprovedBatchExecutor $executor, int $taskId): array
    {
        $method = new ReflectionMethod($executor, 'indexPersistedOperations');
        return $method->invoke($executor, $taskId);
    }

    private function makeExecutor(): ApprovedBatchExecutor
    {
        $factory = Mockery::mock(DriverFactory::class);
        $orch    = new Orchestrator($factory, new OrchestratorConfig());

        return new ApprovedBatchExecutor(
            orchestrator: $orch,
            workerMode: Spora\Agents\ValueObjects\WorkerMode::Sync,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedTask(): array
    {
        $authService = new AuthService(
            new Delight\Auth\Auth(
                Illuminate\Database\Capsule\Manager::connection()->getPdo(),
                null,
                null,
                false,
            ),
        );
        $userId = $authService->register(
            'op-map-' . uniqid() . '@test.local',
            'Password1!',
            'Op Map Test',
        );

        $agent = Spora\Models\Agent::create([
            'user_id'              => $userId,
            'name'                 => 'Op Map Agent',
            'llm_driver_config_id' => null,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'PENDING_APPROVAL',
            'user_prompt' => 'op map test',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        return [$userId, $task->id];
    }
}
