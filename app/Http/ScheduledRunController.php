<?php

declare(strict_types=1);

namespace Spora\Http;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Agents\OrchestratorInterface;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ScheduledRunController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/scheduled-runs
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $runs = ScheduledRun::with('template')
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(ScheduledRun $r) => $this->resource($r));

        return new JsonResponse(['data' => ['scheduled_runs' => $runs->all()]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/scheduled-runs
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validateCreate($body);
        if ($validationError !== null) {
            return $validationError;
        }

        $isRecurring = !empty($body['cron_expression']);
        $nextRunAt = $isRecurring
            ? $this->computeNextRunAt($body['cron_expression'], $body['timezone'] ?? 'UTC')
            : $this->computeOneShotNextRunAt($body['run_at'], $body['timezone'] ?? 'UTC');

        $id = Capsule::table('scheduled_runs')->insertGetId([
            'agent_id'          => $agent->id,
            'template_id'       => isset($body['template_id']) ? (int) $body['template_id'] : null,
            'raw_prompt'        => isset($body['raw_prompt']) ? trim((string) $body['raw_prompt']) : null,
            'cron_expression'   => $isRecurring ? trim((string) $body['cron_expression']) : null,
            'run_at'            => !$isRecurring && isset($body['run_at']) ? $body['run_at'] : null,
            'timezone'          => trim((string) ($body['timezone'] ?? 'UTC')),
            'max_steps_override' => isset($body['max_steps_override']) ? (int) $body['max_steps_override'] : null,
            'is_active'         => isset($body['is_active']) ? ($body['is_active'] ? 1 : 0) : 1,
            'last_run_at'       => null,
            'next_run_at'       => $nextRunAt,
            'user_id'           => $userId,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $run = ScheduledRun::find($id);

        return new JsonResponse(
            ['data' => ['scheduled_run' => $this->resource($run)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $run = $this->findRun((int) $request->attributes->get('runId', 0), $agent->id);

        if ($run === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['scheduled_run' => $this->resource($run)]]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $run = $this->findRun((int) $request->attributes->get('runId', 0), $agent->id);

        if ($run === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $allowed = ['template_id', 'raw_prompt', 'cron_expression', 'run_at', 'timezone', 'max_steps_override', 'is_active'];
        $data    = array_intersect_key($body, array_flip($allowed));

        if ($data !== []) {
            // Normalise booleans and ints
            if (isset($data['is_active'])) {
                $data['is_active'] = $data['is_active'] ? 1 : 0;
            }
            if (array_key_exists('template_id', $data)) {
                $data['template_id'] = $data['template_id'] !== null ? (int) $data['template_id'] : null;
            }
            if (array_key_exists('max_steps_override', $data)) {
                $data['max_steps_override'] = $data['max_steps_override'] !== null ? (int) $data['max_steps_override'] : null;
            }

            // Recompute next_run_at if scheduling fields change
            if (array_key_exists('cron_expression', $data) || array_key_exists('run_at', $data) || array_key_exists('timezone', $data)) {
                $cron    = $data['cron_expression'] ?? $run->cron_expression;
                $runAt   = $data['run_at'] ?? $run->run_at?->toDateTimeString();
                $timezone = $data['timezone'] ?? $run->timezone;
                $isRecurring = !empty($cron);
                $data['next_run_at'] = $isRecurring
                    ? $this->computeNextRunAt($cron, $timezone)
                    : $this->computeOneShotNextRunAt($runAt, $timezone);
            }

            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
            $run->refresh();
        }

        return new JsonResponse(['data' => ['scheduled_run' => $this->resource($run)]]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/scheduled-runs/{runId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $run = $this->findRun((int) $request->attributes->get('runId', 0), $agent->id);

        if ($run === null) {
            return $this->notFound();
        }

        Capsule::table('scheduled_runs')->where('id', $run->id)->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /api/v1/agents/{agentId}/scheduled-runs/{runId}/trigger
     *
     * Immediately creates a task from this scheduled run (one-shot deactivation afterwards).
     */
    public function trigger(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $run = $this->findRun((int) $request->attributes->get('runId', 0), $agent->id);

        if ($run === null) {
            return $this->notFound();
        }

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
            if ($template === null) {
                return $this->error(
                    'TEMPLATE_NOT_FOUND',
                    'The prompt template assigned to this scheduled run no longer exists.',
                    Response::HTTP_NOT_FOUND,
                );
            }
        }

        // Determine prompt
        $prompt = '';
        if ($template !== null) {
            $variablesRaw = $template->getAttribute('variables');
            $variables = is_array($variablesRaw) ? $variablesRaw : [];
            $prompt = $this->substituteVariables($template->prompt_template ?? '', $variables, $agent);
        } else {
            $prompt = $run->raw_prompt ?? '';
            $prompt = $this->substituteVariables($prompt, [], $agent);
        }

        // Determine max_steps
        $maxSteps = $run->max_steps_override
            ?? ($template !== null
                ? ($template->max_steps ?? $agent->max_steps)
                : $agent->max_steps);

        try {
            $task = $this->orchestrator->start($agent->id, $prompt, (int) $maxSteps);
        } catch (Throwable $e) {
            return $this->error(
                'ORCHESTRATOR_ERROR',
                'Failed to start task: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // Update last_run_at
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update([
                'last_run_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

        // One-shot: deactivate after triggering
        if ($run->cron_expression === null) {
            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update(['is_active' => 0]);
        }

        // Publish Mercure update
        $taskData = [
            'id'          => $task->id,
            'agent_id'    => $task->agent_id,
            'status'      => $task->status,
            'user_prompt' => $task->user_prompt,
        ];
        $this->mercure->publish($task->id, $taskData);

        $run->refresh();

        return new JsonResponse(['data' => ['scheduled_run' => $this->resource($run), 'task_id' => $task->id]]);
    }

    /**
     * Validate create payload.
     * Returns an error JsonResponse or null if valid.
     */
    private function validateCreate(array $body): ?JsonResponse
    {
        $isRecurring       = !empty($body['cron_expression']);
        $isOneShot         = !empty($body['run_at']);
        $hasTemplate       = isset($body['template_id']) && is_int($body['template_id']);
        $hasRawPrompt      = isset($body['raw_prompt']) && trim((string) $body['raw_prompt']) !== '';

        if (!$hasTemplate && !$hasRawPrompt) {
            return $this->error(
                'VALIDATION_ERROR',
                'Either template_id or raw_prompt is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($isRecurring && $isOneShot) {
            return $this->error(
                'VALIDATION_ERROR',
                'cron_expression and run_at are mutually exclusive.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$isRecurring && !$isOneShot) {
            return $this->error(
                'VALIDATION_ERROR',
                'Either cron_expression (recurring) or run_at (one-shot) is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($isOneShot) {
            $runAt = $this->parseDateTime($body['run_at']);
            if ($runAt === false) {
                return $this->error(
                    'VALIDATION_ERROR',
                    'run_at must be a valid ISO 8601 datetime.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if ($isRecurring) {
            try {
                new CronExpression($body['cron_expression']);
            } catch (Throwable) {
                return $this->error(
                    'VALIDATION_ERROR',
                    'cron_expression is invalid.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        return null;
    }

    private function computeNextRunAt(string $cronExpression, string $timezone): string
    {
        $cron = new CronExpression($cronExpression);
        $now  = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
    }

    private function computeOneShotNextRunAt(?string $runAt, string $timezone): ?string
    {
        if ($runAt === null) {
            return null;
        }

        $dt = $this->parseDateTime($runAt);
        if ($dt === false) {
            return null;
        }

        return $dt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s');
    }

    private function parseDateTime(string $value): DateTimeImmutable|false
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Substitute {{variable}} placeholders in a template string.
     */
    private function substituteVariables(string $template, array $variables, ?Agent $agent = null): string
    {
        $defaults = [];
        foreach ($variables as $v) {
            if (isset($v['key'])) {
                $defaults[$v['key']] = $v['default_value'] ?? null;
            }
        }

        return preg_replace_callback('/\{\{(\w+)(?::([^}]*))?\}\}/', function (array $m) use ($defaults, $agent): string {
            $key = $m[1];
            $inlineDefault = $m[2] ?? null;

            if ($key === 'current_date' || $key === 'date') {
                return date('Y-m-d');
            }
            if ($key === 'current_time' || $key === 'time') {
                return date('H:i');
            }
            if ($key === 'current_datetime' || $key === 'datetime') {
                return date('Y-m-d\TH:i');
            }
            if ($key === 'agent_name' && $agent !== null) {
                return $agent->name;
            }
            if ($key === 'user_name' && $agent !== null) {
                $user = \Spora\Models\User::find($agent->user_id);
                return $user instanceof \Spora\Models\User ? ($user->username ?? $key) : $key;
            }
            if ($key === 'day_of_week') {
                return date('l');
            }
            if ($key === 'day_of_month') {
                return date('j');
            }
            if ($key === 'month') {
                return date('F');
            }
            if ($key === 'year') {
                return date('Y');
            }

            if (isset($defaults[$key]) && $defaults[$key] !== '') {
                return $defaults[$key];
            }

            return $inlineDefault ?? $m[0];
        }, $template);
    }

    private function findAgent(int $id, int $userId): ?Agent
    {
        return Agent::where('id', $id)->where('user_id', $userId)->first();
    }

    private function findRun(int $id, int $agentId): ?ScheduledRun
    {
        return ScheduledRun::where('id', $id)->where('agent_id', $agentId)->first();
    }

    private function resource(ScheduledRun $run): array
    {
        $run->loadMissing('template');
        /** @var AgentPromptTemplate|null */
        $template = $run->getRelation('template');

        return [
            'id'                => (int) $run->id,
            'agent_id'          => (int) $run->agent_id,
            'template_id'       => $run->template_id,
            'template_name'     => $template?->name,
            'raw_prompt'        => $run->raw_prompt,
            'cron_expression'   => $run->cron_expression,
            'run_at'            => $run->run_at?->toIso8601String(),
            'timezone'          => $run->timezone,
            'max_steps_override' => $run->max_steps_override,
            'is_active'         => (bool) $run->is_active,
            'last_run_at'       => $run->last_run_at?->toIso8601String(),
            'next_run_at'       => $run->next_run_at?->toIso8601String(),
            'created_at'        => $run->created_at->toIso8601String(),
            'updated_at'        => $run->updated_at->toIso8601String(),
        ];
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Scheduled run not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
