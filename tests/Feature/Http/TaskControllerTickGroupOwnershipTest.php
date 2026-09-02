<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\TaskTickController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\DbRateLimiter;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskService;
use Spora\Services\ToolCallSerializer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('TICK_GROUP_PASSWORD') || define('TICK_GROUP_PASSWORD', 'Password1!');
const TICK_GROUP_DT = 'Y-m-d H:i:s';

/**
 * Build a TaskTickController wired to a fake LLM that returns "Done." on
 * every complete() call. Used by the group-ownership tests so the tick
 * itself never throws — the assertions are about the controller's
 * runner-scoping rule, not the LLM.
 *
 * Post-0071: the runner-scoping rule keys off `tasks.principal_id IN
 * visiblePrincipalIds(userId)` rather than `tasks.user_id = userId`.
 * The controller must therefore accept a {@see PrincipalResolver} so it
 * can compute the visible-principal list at tick time.
 *
 * @return array{controller: TaskTickController, auth: AuthService, orchestrator: Orchestrator, mercure: MercurePublisherInterface, resolver: PrincipalResolver}
 */
function makeTickGroupController(): array
{
    $authService = bootAuthLayer();

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_group'));
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);
    $orchestrator = new Orchestrator($factory, new OrchestratorConfig(logger: new NullLogger()));

    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $resolver = new PrincipalResolver();
    $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]), $resolver);
    $controller = new TaskTickController(
        $authService,
        $service,
        WorkerRuntimeMode::Client,
        new DbRateLimiter(),
        $mercure,
        $orchestrator,
        new Spora\Agents\ErrorClassifier(),
        new Spora\Agents\RetryScheduler(),
        null,
        new NullLogger(),
        600,
        $resolver,
    );

    return [
        'controller'  => $controller,
        'auth'        => $authService,
        'orchestrator' => $orchestrator,
        'mercure'     => $mercure,
        'resolver'    => $resolver,
    ];
}

function buildGroupTickRequest(int $taskId): Request
{
    $req = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
    $req->attributes->set('taskId', $taskId);

    return $req;
}

describe('TaskController::tick runner-scoping (group-owned agents)', function (): void {
    it('lets a group member tick a task on a shared agent (post-0071: visiblePrincipalIds gate)', function (): void {
        // Post-0071 the runner-scoping rule is owner-or-group-member:
        // any user whose `visiblePrincipalIds(userId)` includes the
        // task's `principal_id` can tick it. The task itself is owned
        // by the group's principal (not by User A personally), so User B
        // — a plain group member with visibility — must be able to tick
        // and run it to completion.
        $harness = makeTickGroupController();

        $userAId = $harness['auth']->register('group-a@example.com', TICK_GROUP_PASSWORD, 'GroupA');
        $userBId = $harness['auth']->register('group-b@example.com', TICK_GROUP_PASSWORD, 'GroupB');
        // register() does not auto-materialise user-principals — they
        // are required for PrincipalResolver::visiblePrincipalIds() to
        // return a non-empty list for both A and B.
        $this->createUserPrincipal($userAId);
        $this->createUserPrincipal($userBId);

        // Group G owns agent X; both A and B are members.
        $groupPrincipalId = $this->makeGroupPrincipal($userAId, 'Tick Group');
        Capsule::table('group_memberships')->insertOrIgnore([
            'group_id'   => Capsule::table('principals')->where('id', $groupPrincipalId)->value('group_id'),
            'user_id'    => $userBId,
            'role'       => 'member',
            'created_at' => date(TICK_GROUP_DT),
            'updated_at' => date(TICK_GROUP_DT),
        ]);
        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Tick Group Global',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'                 => 'Tick Group Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);

        // User A starts the task — its trigger is A, but the principal
        // is the group's. Post-0071 `principal_id` mirrors
        // `agents.principal_id` at creation time.
        $task = Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $userAId,
            'status'      => 'QUEUED',
            'user_prompt' => 'group tick target',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        // User B ticks. Their `visiblePrincipalIds` includes the
        // group-principal, so the new `tasks.principal_id IN
        // visiblePrincipalIds` gate lets the tick through.
        simulateLoggedInSession($userBId, 'group-b@example.com');

        $response = $harness['controller']->tick(buildGroupTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $task->refresh();
        // Row must have been claimed and run to completion by B's call.
        expect($task->status)->toBe('COMPLETED');
    });

    it('lets the original trigger tick their own task', function (): void {
        $harness = makeTickGroupController();
        $userAId = $harness['auth']->register('group-a2@example.com', TICK_GROUP_PASSWORD, 'GroupA2');
        $this->createUserPrincipal($userAId);

        $groupPrincipalId = $this->makeGroupPrincipal($userAId, 'Tick Group 2');
        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Tick Group 2 Global',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'                 => 'Tick Group 2 Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
        $task = Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $userAId,
            'status'      => 'QUEUED',
            'user_prompt' => 'self tick target',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        simulateLoggedInSession($userAId, 'group-a2@example.com');

        $response = $harness['controller']->tick(buildGroupTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $task->refresh();
        expect($task->status)->toBe('COMPLETED');
    });
});
