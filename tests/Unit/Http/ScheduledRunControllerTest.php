<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Http\ScheduledRunController;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\ScheduledRunService;

function makeScheduledRunController(): array
{
    $authService = bootAuthLayer();
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps) {
        return Spora\Models\Task::create([
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

    $scheduledRunService = new ScheduledRunService($orchestrator, $mercure);

    $controller = new ScheduledRunController($authService, $scheduledRunService);

    return [$controller, $authService, $orchestrator, $mercure, $scheduledRunService];
}

function registerAndGetAgentForScheduledRun(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('scheduled@example.com', 'Password1!', 'Scheduled');
    simulateLoggedInSession($userId, 'scheduled@example.com');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'ScheduledTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id, $authService];
}

function makeJsonRequestWithAttrs(string $method, string $path, array $body, array $attrs): Symfony\Component\HttpFoundation\Request
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
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs", [], ['id' => $agentId]);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_runs'])->toHaveCount(1);
        expect($body['data']['scheduled_runs'][0]['cron_expression'])->toBe('0 9 * * *');
    });

    it('index returns empty array when no scheduled runs', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs", [], ['id' => $agentId]);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_runs'])->toHaveCount(0);
    });

    it('index returns 404 for agent belonging to another user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('owner@example.com', 'Password1!', 'Owner');
        $otherUserId = $authService->register('other@example.com', 'Password1!', 'Other');
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
        ], ['id' => $agentId]);
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
        ], ['id' => $agentId]);
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
        ], ['id' => $agentId]);
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
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
    });

    it('store returns 422 when cron_expression is invalid', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Invalid cron',
            'cron_expression' => 'not-a-cron',
        ], ['id' => $agentId]);
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
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [], ['id' => $agentId, 'runId' => $run->id]);
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
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [], ['id' => $agentId, 'runId' => $run->id]);
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
        ], ['id' => $agentId, 'runId' => $run->id]);
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
        $request = makeJsonRequestWithAttrs('DELETE', "/api/v1/agents/{$agentId}/scheduled-runs/{$runId}", [], ['id' => $agentId, 'runId' => $runId]);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBe(true);
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

        // Pre-create PENDING entry (as store() would have done)
        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'status'          => 'PENDING',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        [$controller, $authService, $orchestrator] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['id' => $agentId, 'runId' => $run->id]);
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
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['id' => $agentId, 'runId' => $run->id]);
        $response = $controller->trigger($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['task_id'])->not->toBeNull();
    });

    it('store computes next_run_at correctly in Europe/Berlin timezone for one-shot', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        // User selects 10:00 in Europe/Berlin (CEST, UTC+02:00) → 08:00 UTC
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt' => 'Berlin test',
            'run_at'     => '2026-04-20T10:00:00+02:00',
            'timezone'   => 'Europe/Berlin',
            'is_active'  => true,
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        // next_run_at must be 2026-04-20 10:00:00 in Europe/Berlin, which is 08:00 UTC
        // The stored value is Y-m-d H:i:s in the timezone, so it should be 2026-04-20 10:00:00
        // When serialized to ISO8601 it becomes 2026-04-20T10:00:00+02:00
        $nextRunAt = $body['data']['scheduled_run']['next_run_at'];
        expect($nextRunAt)->not->toBeNull();
        // The ISO8601 string must reflect 10:00 Berlin time, not a different hour
        $parsed = new DateTimeImmutable($nextRunAt);
        $berlin = $parsed->setTimezone(new DateTimeZone('Europe/Berlin'));
        expect((int) $berlin->format('H'))->toBe(10);
        expect((int) $berlin->format('i'))->toBe(0);
    });

    it('store computes next_run_at correctly in Europe/Berlin timezone for recurring', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        // Daily at 09:00 Berlin
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Berlin daily',
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'Europe/Berlin',
            'is_active'       => true,
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        $nextRunAt = $body['data']['scheduled_run']['next_run_at'];
        expect($nextRunAt)->not->toBeNull();
        // Next run must be 09:00 Berlin time
        $parsed = new DateTimeImmutable($nextRunAt);
        $berlin = $parsed->setTimezone(new DateTimeZone('Europe/Berlin'));
        expect((int) $berlin->format('H'))->toBe(9);
        expect((int) $berlin->format('i'))->toBe(0);
    });

    it('store normalizes run_at from ISO 8601 offset to UTC Y-m-d H:i:s', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        // Frontend sends: 10:00 in Europe/Berlin (CEST, +02:00) → UTC is 08:00
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt' => 'Normalize test',
            'run_at'     => '2026-04-20T10:00:00+02:00',
            'timezone'   => 'Europe/Berlin',
            'is_active'  => true,
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        $runAtReturned = $body['data']['scheduled_run']['run_at'];
        expect($runAtReturned)->not->toBeNull();
        // run_at in response must be 10:00 Berlin (with offset +02:00), not 08:00 UTC
        $parsed = new DateTimeImmutable($runAtReturned);
        $berlin = $parsed->setTimezone(new DateTimeZone('Europe/Berlin'));
        expect((int) $berlin->format('H'))->toBe(10);
    });

    it('store creates a PENDING scheduled_runs_next entry for recurring runs', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt'      => 'Daily check',
            'cron_expression' => '0 9 * * *',
            'timezone'        => 'UTC',
            'is_active'       => true,
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        $runId = $body['data']['scheduled_run']['id'];

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->status)->toBe('PENDING');
    });

    it('store creates a PENDING scheduled_runs_next entry for one-shot runs', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
            'raw_prompt' => 'One-time task',
            'run_at'     => date('c', strtotime('+1 hour')),
            'timezone'   => 'UTC',
            'is_active'  => true,
        ], ['id' => $agentId]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        $runId = $body['data']['scheduled_run']['id'];

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->status)->toBe('PENDING');
    });

    it('trigger marks PENDING entry as DONE and inserts next PENDING for recurring runs', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'Trigger me',
            'cron_expression' => '0 9 * * *',
            'run_at'        => null,
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        // Pre-create a PENDING entry (simulating what store() would have done)
        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'status'          => 'PENDING',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['id' => $agentId, 'runId' => $run->id]);
        $response = $controller->trigger($request);

        expect($response->getStatusCode())->toBe(200);

        // Previous PENDING should now be DONE
        $doneEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', 'DONE')
            ->first();
        expect($doneEntry)->not->toBeNull();

        // A new PENDING entry should exist for the recurring schedule
        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', 'PENDING')
            ->first();
        expect($nextEntry)->not->toBeNull();
        expect($nextEntry->due_at)->not->toBeNull();
    });

    it('trigger marks PENDING entry as DONE with no new entry for one-shot', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'One-shot task',
            'cron_expression' => null,
            'run_at'        => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'status'          => 'PENDING',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('POST', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}/trigger", [], ['id' => $agentId, 'runId' => $run->id]);
        $response = $controller->trigger($request);

        expect($response->getStatusCode())->toBe(200);

        // Previous PENDING should now be DONE
        $doneEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', 'DONE')
            ->first();
        expect($doneEntry)->not->toBeNull();

        // No new PENDING entry for one-shot
        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', 'PENDING')
            ->count();
        expect($pendingCount)->toBe(0);
    });

    it('resource returns next_run_at from PENDING scheduled_runs_next entry', function (): void {
        [$userId, $agentId] = registerAndGetAgentForScheduledRun();

        $run = ScheduledRun::create([
            'agent_id'      => $agentId,
            'user_id'       => $userId,
            'raw_prompt'    => 'Show me',
            'cron_expression' => '0 9 * * *',
            'timezone'      => 'UTC',
            'is_active'     => true,
            'next_run_at'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        // Insert a PENDING entry with a deterministic 09:00 UTC due_at
        $futureDue = date('Y-m-d 09:00:00', strtotime('+2 days'));
        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => $futureDue,
            'status'          => 'PENDING',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        [$controller] = makeScheduledRunController();
        $request = makeJsonRequestWithAttrs('GET', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [], ['id' => $agentId, 'runId' => $run->id]);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['scheduled_run']['next_run_at'])->not->toBeNull();
        // next_run_at should reflect the PENDING entry's due_at (UTC 09:00 next day = ISO8601)
        $parsed = new DateTimeImmutable($body['data']['scheduled_run']['next_run_at']);
        expect((int) $parsed->format('H'))->toBe(9);
    });
});
