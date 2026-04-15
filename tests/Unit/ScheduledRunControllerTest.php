<?php

declare(strict_types=1);

use Cron\CronExpression;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\ScheduledRunController;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;

function makeScheduledRunController(): array
{
    $authService = bootAuthLayer();
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps) {
        return \Spora\Models\Task::create([
            'agent_id'    => $agentId,
            'user_id'     => 1,
            'status'      => 'RUNNING',
            'user_prompt' => $prompt,
            'max_steps'   => $maxSteps,
            'step_count'  => 0,
        ]);
    });
    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    $controller = new ScheduledRunController($authService, $orchestrator, $mercure);

    return [$controller, $authService, $orchestrator, $mercure];
}

function registerAndGetAgentForScheduledRun(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('scheduled@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'scheduled@example.com');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'ScheduledTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id, $authService];
}

function makeJsonRequestWithAttrs(string $method, string $path, array $body, array $attrs): \Symfony\Component\HttpFoundation\Request
{
    $request = jsonRequest($method, $path, $body);
    foreach ($attrs as $k => $v) {
        $request->attributes->set($k, $v);
    }
    return $request;
}

describe('ScheduledRunController', function (): void {
    it('index returns scheduled runs for the agent', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        ScheduledRun::create([
            'agent_id'    => $agentId,
            'user_id'     => $userId,
            'template_id' => null,
            'raw_prompt'  => 'Run me daily',
            'cron_expression' => '0 9 * * *',
            'timezone'    => 'UTC',
            'is_active'   => true,
            'next_run_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs", [], ['agentId' => $agentId]);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_runs'])->toHaveCount(1);
        expect($body['data']['scheduled_runs'][0]['cron_expression'])->toBe('0 9 * * *');
    });

    it('index returns empty array when no scheduled runs', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs", [], ['agentId' => $agentId]);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_runs'])->toHaveCount(0);
    });

    it('index returns 404 for agent belonging to another user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('owner@example.com', 'Password1!');
        $otherUserId = $authService->register('other@example.com', 'Password1!');
        simulateLoggedInSession($otherUserId, 'other@example.com');

        $agent = Agent::create([
            'user_id' => $userId,
            'name'    => 'OtherUserAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agent->id}/scheduled-runs", [], ['agentId' => $agent->id]);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(404);
    });

    it('store creates a recurring scheduled run with cron_expression', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Daily briefing',
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ], ['agentId' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_run']['cron_expression'])->toBe('0 9 * * *');
        expect($body['data']['scheduled_run']['raw_prompt'])->toBe('Daily briefing');
        expect($body['data']['scheduled_run']['next_run_at'])->not->toBeNull();
    });

    it('store creates a one-shot scheduled run with run_at', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt' => 'One-time task',
            'run_at'     => date('c', strtotime('+1 hour')),
            'timezone'   => 'UTC',
            'is_active'  => true,
        ], ['agentId' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_run']['run_at'])->not->toBeNull();
        expect($body['data']['scheduled_run']['cron_expression'])->toBeNull();
    });

    it('store returns 422 when neither template_id nor raw_prompt is provided', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'cron_expression' => '0 9 * * *',
        ], ['agentId' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('store returns 422 when both cron_expression and run_at are provided', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Conflicting',
            'cron_expression' => '0 9 * * *',
            'run_at'          => date('c', strtotime('+1 hour')),
        ], ['agentId' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
    });

    it('store returns 422 when cron_expression is invalid', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Invalid cron',
            'cron_expression' => 'not-a-cron',
        ], ['agentId' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
    });

    it('show returns a single scheduled run', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'Show run',
            'cron_expression' => '0 9 * * *',
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [], ['agentId' => $agentId, 'runId' => $run->id]);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_run']['id'])->toBe($run->id);
    });

    it('show returns 404 for run belonging to another agent', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $otherAgent = Agent::create([
            'user_id' => $userId,
            'name'    => 'OtherAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);
        $run = ScheduledRun::create([
            'agent_id'      => $otherAgent->id,
            'user_id'       => $userId,
            'raw_prompt'    => 'Other run',
            'cron_expression' => '0 9 * * *',
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [], ['agentId' => $agentId, 'runId' => $run->id]);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(404);
    });

    it('update modifies a scheduled run', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'Original prompt',
            'cron_expression' => '0 9 * * *',
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('PUT', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [
            'raw_prompt' => 'Updated prompt',
            'is_active'  => false,
        ], ['agentId' => $agentId, 'runId' => $run->id]);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_run']['raw_prompt'])->toBe('Updated prompt');
        expect($body['data']['scheduled_run']['is_active'])->toBe(false);
    });

    it('destroy deletes a scheduled run', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'To delete',
            'cron_expression' => '0 9 * * *',
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        $runId = $run->id;

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('DELETE', "/api/v1/agents/{$agentId}/scheduled-runs/{$runId}", [], ['agentId' => $agentId, 'runId' => $runId]);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(204);
        expect(ScheduledRun::find($runId))->toBeNull();
    });

    it('trigger creates a task from the scheduled run', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'Trigger me',
            'cron_expression' => null,
            'run_at'        => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        [$controller, $authService, $orchestrator] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['agentId' => $agentId, 'runId' => $run->id]);
        $response = $controller->trigger($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['task_id'])->not->toBeNull();

        // One-shot should be deactivated after trigger
        $run->refresh();
        expect($run->is_active)->toBe(false);
    });

    it('trigger with template_id uses template prompt and max_steps', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $template = AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'Template Prompt',
            'prompt_template' => 'Template prompt for {{topic}}',
            'variables' => json_encode(['topic' => 'default']),
            'max_steps' => 7,
            'is_active' => true,
        ]);

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'template_id'   => $template->id,
            'raw_prompt'    => null,
            'cron_expression' => null,
            'run_at'        => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        [$controller, $authService, $orchestrator] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['agentId' => $agentId, 'runId' => $run->id]);
        $response = $controller->trigger($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['task_id'])->not->toBeNull();
    });
});