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
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\DecisionsRequestValidator;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\DbRateLimiter;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Services\ToolCallSerializer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('TICK_GROUP_PASSWORD') || define('TICK_GROUP_PASSWORD', 'Password1!');
const TICK_GROUP_DT = 'Y-m-d H:i:s';

/**
 * Build a TaskController wired to a fake LLM that returns "Done." on
 * every complete() call. Used by the group-ownership tests so the tick
 * itself never throws — the assertions are about the controller's
 * runner-scoping rule, not the LLM.
 *
 * @return array{controller: TaskController, auth: AuthService, orchestrator: Orchestrator, mercure: MercurePublisherInterface}
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

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]));
    $mediaCapability = new Spora\Services\MediaArchive\TaskMediaCapabilityService();
    $controller = new TaskController(
        $authService,
        $service,
        $mediaCapability,
        new ContinueTaskDispatcher($service, $mediaCapability),
        new DecisionsRequestValidator($service),
        WorkerRuntimeMode::Client,
        new DbRateLimiter(),
        $mercure,
        $orchestrator,
        600,
    );

    return [
        'controller'  => $controller,
        'auth'        => $authService,
        'orchestrator' => $orchestrator,
        'mercure'     => $mercure,
    ];
}

function buildGroupTickRequest(int $taskId): Request
{
    $req = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
    $req->attributes->set('taskId', $taskId);

    return $req;
}

describe('TaskController::tick runner-scoping (group-owned agents)', function (): void {
    it('returns 404 when user_id differs from current user even if agent is group-owned', function (): void {
        // Only one worker picks up a chat. Even though both users can see
        // the group-owned agent, the task is scoped to its runner
        // (`tasks.user_id = User A`). User B's browser must 404 (NOT 403)
        // — same existence-hiding rationale as the not-owned case.
        $harness = makeTickGroupController();

        $userAId = $harness['auth']->register('group-a@example.com', TICK_GROUP_PASSWORD, 'GroupA');
        $userBId = $harness['auth']->register('group-b@example.com', TICK_GROUP_PASSWORD, 'GroupB');

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

        // User A starts the task — its runner.
        $task = Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => $groupPrincipalId,
            'user_id'     => $userAId,
            'status'      => 'QUEUED',
            'user_prompt' => 'group tick target',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        // User B ticks. They have visibility (same group), but tasks.user_id
        // filters them out — the controller returns 404.
        simulateLoggedInSession($userBId, 'group-b@example.com');

        $response = $harness['controller']->tick(buildGroupTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $task->refresh();
        // Row must NOT have been claimed by B's call.
        expect($task->status)->toBe('QUEUED')
            ->and($task->user_id)->toBe($userAId);
    });

    it('does NOT 404 when user_id matches the current user', function (): void {
        $harness = makeTickGroupController();
        $userAId = $harness['auth']->register('group-a2@example.com', TICK_GROUP_PASSWORD, 'GroupA2');

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
            'user_id'     => $userAId,
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
