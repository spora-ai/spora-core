<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\User;

/**
 * Unit tests for buildMessages summary-skipping logic.
 * Uses reflection to call the private buildMessages method on a real Orchestrator.
 */
final class BuildMessagesSummaryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require 'vendor/autoload.php';

        $db = new \Spora\Core\Database([
            'db_driver' => 'sqlite',
            'db_path'   => ':memory:',
        ], null);
        $db->boot();

        // Create a user and agent for FK constraints
        $userId = User::insertGetId([
            'email'     => 'test' . time() . '@example.com',
            'password'  => password_hash('Password1!', PASSWORD_DEFAULT),
            'username'  => null,
            'status'    => 1,
            'verified'  => 1,
            'roles_mask' => 0,
            'registered' => time(),
        ]);
        self::$userId = $userId;
        self::$agentId = self::createAgent(self::$userId);
    }

    private static int $userId;
    private static int $agentId;

    private static function createAgent(int $userId): int
    {
        $agent = Agent::create([
            'user_id' => $userId,
            'name' => 'Test Agent',
            'llm_provider' => 'openai_compatible',
            'llm_model' => 'gpt-4o',
            'max_steps' => 10,
            'is_active' => true,
        ]);
        return $agent->id;
    }

    private static function createTask(int $agentId, int $userId): Task
    {
        return Task::create([
            'agent_id' => $agentId,
            'user_id' => $userId,
            'status' => 'RUNNING',
            'user_prompt' => 'Test',
            'step_count' => 0,
            'max_steps' => 10,
        ]);
    }

    private function callBuildMessages(Task $task): array
    {
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->allows()->debug()->andReturnNull();
        $logger->allows()->info()->andReturnNull();
        $logger->allows()->warning()->andReturnNull();
        $logger->allows()->error()->andReturnNull();

        $driverFactory = new \Spora\Drivers\DriverFactory(
            logger: $logger,
            llmConfigService: new \Spora\Services\LLMConfigService(
                new \Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                [],
            ),
        );

        $orch = new \Spora\Agents\Orchestrator(
            driverFactory: $driverFactory,
            toolInstances: [],
        );

        $ref = new ReflectionMethod(\Spora\Agents\Orchestrator::class, 'buildMessages');

        return $ref->invoke($orch, $task->id);
    }

    public function testSummaryRowReplacesPriorMessages(): void
    {
        $task = self::createTask(self::$agentId, self::$userId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'Hi there']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'What is the time?']);
        TaskHistory::create([
            'task_id' => $task->id,
            'sequence' => 3,
            'role' => 'summary',
            'content' => 'User asked about time',
            'summarized_sequence_range' => '0-2',
        ]);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Thanks']);

        $messages = $this->callBuildMessages($task);

        $this->assertCount(2, $messages);
        $this->assertEquals('summary', $messages[0]['role']);
        $this->assertEquals('User asked about time', $messages[0]['content']);
        $this->assertEquals('user', $messages[1]['role']);
        $this->assertEquals('Thanks', $messages[1]['content']);

        $task->delete();
    }

    public function testMultipleSummariesOnlyMostRecentSurvives(): void
    {
        $task = self::createTask(self::$agentId, self::$userId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'First']);
        TaskHistory::create([
            'task_id' => $task->id,
            'sequence' => 1,
            'role' => 'summary',
            'content' => 'First summary',
            'summarized_sequence_range' => '0-0',
        ]);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Second']);
        TaskHistory::create([
            'task_id' => $task->id,
            'sequence' => 3,
            'role' => 'summary',
            'content' => 'Second summary',
            'summarized_sequence_range' => '2-2',
        ]);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Recent']);

        $messages = $this->callBuildMessages($task);

        // When summary-2 (seq 3, range 2-2) is encountered, it removes messages with _seq <= 2.
        // summary-1 (seq 1) is NOT in range 2-2, so it is preserved.
        // Final: First summary + Second summary + Recent = 3 messages.
        $this->assertCount(3, $messages);
        $this->assertEquals('summary', $messages[0]['role']);
        $this->assertEquals('First summary', $messages[0]['content']);
        $this->assertEquals('summary', $messages[1]['role']);
        $this->assertEquals('Second summary', $messages[1]['content']);
        $this->assertEquals('user', $messages[2]['role']);
        $this->assertEquals('Recent', $messages[2]['content']);

        $task->delete();
    }

    public function testNoSummaryRowsSentAsIs(): void
    {
        $task = self::createTask(self::$agentId, self::$userId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'Hi']);

        $messages = $this->callBuildMessages($task);

        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('Hello', $messages[0]['content']);
        $this->assertEquals('assistant', $messages[1]['role']);
        $this->assertEquals('Hi', $messages[1]['content']);

        $task->delete();
    }
}
