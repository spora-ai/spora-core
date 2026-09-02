<?php

declare(strict_types=1);

namespace Spora\Services;

use Cron\CronExpression;
use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\User;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\PromptTemplateMissingException;
use Spora\Services\Exceptions\ScheduledRunNotFoundException;
use Throwable;

/**
 * Service for scheduled run management.
 * All DB access for ScheduledRun domain goes through this service.
 */
final class ScheduledRunService implements ScheduledRunServiceInterface
{
    private const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public const VISIBILITY_READ = true;
    public const VISIBILITY_WRITE = false;

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
        ?PrincipalService $principalService = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    private readonly PrincipalService $principalService;
    private readonly PrincipalResolver $principalResolver;

    public function getRunsForAgent(int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_READ);
        if ($agent === null) {
            return null;
        }

        $runs = ScheduledRun::with('template')
            ->where('agent_id', $agentId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(ScheduledRun $r) => $this->resource($r));

        return $runs->all();
    }

    public function createRun(int $agentId, int $userId, array $data): array
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_WRITE);
        if ($agent === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        $isRecurring = !empty($data['cron_expression']);
        $timezone   = $data['timezone'] ?? 'UTC';
        // Defensive: the controller already validates, but non-HTTP callers (CLI, plugins,
        // seeders) bypass it. Use timezone_identifiers_list() rather than new DateTimeZone()
        // to avoid a useless-instantiation lint warning.
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new DateInvalidTimeZoneException(sprintf('Invalid IANA timezone: %s', $timezone));
        }
        $nextRunAt = $isRecurring
            ? $this->computeNextRunAt($data['cron_expression'], $timezone)
            : $this->computeOneShotNextRunAt($data['run_at'] ?? null, $timezone);

        $isActive = match (true) {
            !isset($data['is_active']) => 1,
            (bool) $data['is_active']   => 1,
            default                     => 0,
        };

        $id = Capsule::table('scheduled_runs')->insertGetId([
            'agent_id'          => $agentId,
            'template_id'       => isset($data['template_id']) ? (int) $data['template_id'] : null,
            'raw_prompt'        => isset($data['raw_prompt']) ? trim((string) $data['raw_prompt']) : null,
            'cron_expression'   => $isRecurring ? trim((string) $data['cron_expression']) : null,
            'run_at'            => !$isRecurring && isset($data['run_at'])
                ? $this->normalizeRunAtToUtc($data['run_at'], $timezone)
                : null,
            'timezone'          => trim((string) ($data['timezone'] ?? 'UTC')),
            'max_steps_override' => isset($data['max_steps_override']) ? (int) $data['max_steps_override'] : null,
            'is_active'         => $isActive,
            'last_run_at'       => null,
            'next_run_at'       => $nextRunAt,
            'user_id'           => $userId,
            'created_at'        => gmdate(self::DB_TIMESTAMP_FORMAT),
            'updated_at'        => gmdate(self::DB_TIMESTAMP_FORMAT),
        ]);

        // Insert first PENDING entry into scheduled_runs_next
        if ($nextRunAt !== null) {
            Capsule::table('scheduled_runs_next')->insert([
                'scheduled_run_id' => $id,
                'due_at'           => $nextRunAt,
                'status'           => ScheduledRunNext::STATUS_PENDING,
                'created_at'       => gmdate(self::DB_TIMESTAMP_FORMAT),
                'updated_at'       => gmdate(self::DB_TIMESTAMP_FORMAT),
            ]);
        }

        $run = ScheduledRun::find($id);

        return ['scheduled_run' => $this->resource($run)];
    }

    public function getRun(int $runId, int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_READ);
        if ($agent === null) {
            return null;
        }

        $run = $this->findRun($runId, $agentId);
        if ($run === null) {
            return null;
        }

        return ['scheduled_run' => $this->resource($run)];
    }

    public function updateRun(int $runId, int $agentId, int $userId, array $data): ?array
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_WRITE);
        $run = $agent !== null ? $this->findRun($runId, $agentId) : null;

        if ($agent === null || $run === null) {
            return null;
        }

        $allowed = ['template_id', 'raw_prompt', 'cron_expression', 'run_at', 'timezone', 'max_steps_override', 'is_active'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            $this->normalizeUpdateFields($updateData);
            $this->recomputeNextRunAtIfNeeded($updateData, $run);

            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update(array_merge($updateData, ['updated_at' => gmdate(self::DB_TIMESTAMP_FORMAT)]));
            $run->refresh();
        }

        return ['scheduled_run' => $this->resource($run)];
    }

    /**
     * @param array<string, mixed> $updateData
     */
    private function normalizeUpdateFields(array &$updateData): void
    {
        if (isset($updateData['is_active'])) {
            $updateData['is_active'] = $updateData['is_active'] ? 1 : 0;
        }
        if (array_key_exists('template_id', $updateData)) {
            $updateData['template_id'] = $updateData['template_id'] !== null ? (int) $updateData['template_id'] : null;
        }
        if (array_key_exists('max_steps_override', $updateData)) {
            $updateData['max_steps_override'] = $updateData['max_steps_override'] !== null ? (int) $updateData['max_steps_override'] : null;
        }
    }

    /**
     * @param array<string, mixed> $updateData
     */
    private function recomputeNextRunAtIfNeeded(array &$updateData, ScheduledRun $run): void
    {
        $scheduleChanged = array_key_exists('cron_expression', $updateData)
            || array_key_exists('run_at', $updateData)
            || array_key_exists('timezone', $updateData);
        if (!$scheduleChanged) {
            return;
        }

        $cron = $updateData['cron_expression'] ?? $run->cron_expression;
        $timezone = $updateData['timezone'] ?? $run->timezone;

        if (array_key_exists('run_at', $updateData) && is_string($updateData['run_at'])) {
            $updateData['run_at'] = $this->normalizeRunAtToUtc($updateData['run_at'], $timezone);
        }

        $runAt = $updateData['run_at'] ?? $run->run_at?->toDateTimeString();
        $isRecurring = !empty($cron);
        $updateData['next_run_at'] = $isRecurring
            ? $this->computeNextRunAt($cron, $timezone)
            : $this->computeOneShotNextRunAt($runAt, $timezone);

        if ($updateData['next_run_at'] !== null) {
            $this->reschedulePendingEntries($run->id, $updateData['next_run_at']);
        }
    }

    private function reschedulePendingEntries(int $scheduledRunId, string $nextRunAt): void
    {
        $now = gmdate(self::DB_TIMESTAMP_FORMAT);
        Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $scheduledRunId)
            ->whereIn('status', [ScheduledRunNext::STATUS_PENDING, ScheduledRunNext::STATUS_CLAIMED])
            ->update([
                'status'       => ScheduledRunNext::STATUS_SKIPPED,
                'completed_at' => $now,
            ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $scheduledRunId,
            'due_at'          => $nextRunAt,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function deleteRun(int $runId, int $agentId, int $userId): bool
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_WRITE);
        if ($agent === null) {
            return false;
        }

        $run = $this->findRun($runId, $agentId);
        if ($run === null) {
            return false;
        }

        Capsule::table('scheduled_runs')->where('id', $runId)->delete();

        return true;
    }

    public function triggerRun(int $runId, int $agentId, int $userId): array
    {
        $agent = $this->findAgent($agentId, $userId, self::VISIBILITY_WRITE);
        if ($agent === null) {
            throw new AgentNotFoundException('Agent not found');
        }

        $run = $this->findRun($runId, $agentId);
        if ($run === null) {
            throw new ScheduledRunNotFoundException('Scheduled run not found');
        }

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
            if ($template === null) {
                throw new PromptTemplateMissingException('The prompt template assigned to this scheduled run no longer exists.');
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
        if ($run->max_steps_override !== null) {
            $maxSteps = $run->max_steps_override;
        } elseif ($template !== null) {
            $maxSteps = $template->max_steps ?? $agent->max_steps;
        } else {
            $maxSteps = $agent->max_steps;
        }

        $task = $this->orchestrator->start($agent->id, $prompt, (int) $maxSteps);

        $lastRunAt = gmdate(self::DB_TIMESTAMP_FORMAT);

        // Mark the current PENDING entry as DONE
        Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->update([
                'status'       => ScheduledRunNext::STATUS_DONE,
                'claimed_at'   => $lastRunAt,
                'completed_at' => $lastRunAt,
            ]);

        // Insert next PENDING entry for recurring schedules.
        if ($run->cron_expression !== null) {
            // Use wall-clock now as cron reference to avoid same-day skip (same fix as
            // in WorkerRunCommand). last_run_at is still tracked for historical accuracy.
            $nowInScheduleTz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($run->timezone));

            $nextDueAt = (new CronExpression($run->cron_expression))
                ->getNextRunDate($nowInScheduleTz, 0, false, $run->timezone)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::DB_TIMESTAMP_FORMAT);

            // Drop any stale PENDING/CLAIMED entry for the same due_at so the
            // unique (scheduled_run_id, due_at) index cannot collide. The
            // ->insertOrIgnore() below is the dialect-portable safety net
            // (INSERT OR IGNORE on SQLite, INSERT IGNORE on MariaDB/MySQL) for
            // any concurrent inserter the DELETE missed.
            Capsule::table('scheduled_runs_next')
                ->where('scheduled_run_id', $run->id)
                ->where('due_at', $nextDueAt)
                ->whereIn('status', [ScheduledRunNext::STATUS_PENDING, ScheduledRunNext::STATUS_CLAIMED])
                ->delete();

            Capsule::table('scheduled_runs_next')->insertOrIgnore([
                'scheduled_run_id' => $run->id,
                'due_at'           => $nextDueAt,
                'status'           => ScheduledRunNext::STATUS_PENDING,
                'created_at'       => $lastRunAt,
                'updated_at'       => $lastRunAt,
            ]);

            // Update cached next_run_at on scheduled_runs
            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update([
                    'last_run_at' => $lastRunAt,
                    'next_run_at' => $nextDueAt,
                    'updated_at'  => $lastRunAt,
                ]);
        } else {
            // One-shot: update last_run_at, clear next_run_at cache, deactivate
            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update([
                    'last_run_at' => $lastRunAt,
                    'next_run_at' => null,
                    'is_active'   => 0,
                    'updated_at'  => $lastRunAt,
                ]);
        }

        // Publish Mercure update
        $taskData = [
            'id'          => $task->id,
            'agent_id'    => $task->agent_id,
            'status'      => $task->status,
            'user_prompt' => $task->user_prompt,
        ];
        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), $taskData);

        $run->refresh();

        return ['scheduled_run' => $this->resource($run), 'task_id' => $task->id];
    }

    private function findAgent(int $id, int $userId, bool $forRead): ?Agent
    {
        // Migration 0067: an agent is owned by a principal, not a user.
        // `forRead` widens visibility to any principal-membership row so a
        // plain group `member` can list + read the schedule; `forWrite`
        // keeps the destructive / configuration paths under the strict
        // owner-or-admin gate.
        $agent = Agent::find($id);
        if ($agent === null) {
            return null;
        }

        if ($forRead) {
            return $this->principalResolver->isVisibleTo($id, $userId) ? $agent : null;
        }

        return $this->principalService->callerControlsPrincipal($userId, (int) $agent->principal_id) ? $agent : null;
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

        // Get next_run_at from the next PENDING entry in scheduled_runs_next
        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->orderBy('due_at')
            ->first();

        $nextRunAt = null;
        if ($nextEntry !== null) {
            $nextRunAt = (new DateTimeImmutable($nextEntry->due_at, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($run->timezone))
                ->format('Y-m-d\TH:i:sP');
        } elseif ($run->next_run_at !== null) {
            // Fall back to cached next_run_at when no PENDING entry exists
            $nextRunAt = (new DateTimeImmutable($run->next_run_at->toDateTimeString(), new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($run->timezone))
                ->format('Y-m-d\TH:i:sP');
        }

        return [
            'id'                => (int) $run->id,
            'agent_id'          => (int) $run->agent_id,
            'template_id'       => $run->template_id,
            'template_name'     => $template?->name,
            'raw_prompt'        => $run->raw_prompt,
            'cron_expression'   => $run->cron_expression,
            'run_at'            => $run->run_at !== null
                ? $run->run_at->setTimezone(new DateTimeZone($run->timezone))->toIso8601String()
                : null,
            'timezone'          => $run->timezone,
            'max_steps_override' => $run->max_steps_override,
            'is_active'         => (bool) $run->is_active,
            'last_run_at'       => $run->last_run_at?->toIso8601String(),
            'next_run_at'       => $nextRunAt,
            'created_at'        => $run->created_at->toIso8601String(),
            'updated_at'        => $run->updated_at->toIso8601String(),
        ];
    }

    private function computeNextRunAt(string $cronExpression, string $timezone): string
    {
        $cron = new CronExpression($cronExpression);
        $now  = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return $cron->getNextRunDate($now, 0, false, $timezone)->setTimezone(new DateTimeZone('UTC'))->format(self::DB_TIMESTAMP_FORMAT);
    }

    private function computeOneShotNextRunAt(?string $runAt, string $timezone = 'UTC'): ?string
    {
        if ($runAt === null) {
            return null;
        }

        $dt = $this->parseDateTime($runAt, $timezone);
        if ($dt === false) {
            return null;
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::DB_TIMESTAMP_FORMAT);
    }

    private function normalizeRunAtToUtc(string $runAt, string $timezone = 'UTC'): string
    {
        $dt = $this->parseDateTime($runAt, $timezone);
        if ($dt === false) {
            return $runAt;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::DB_TIMESTAMP_FORMAT);
    }

    private function parseDateTime(string $value, string $timezone = 'UTC'): DateTimeImmutable|false
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone($timezone));
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

            $resolved = $this->resolveBuiltInPlaceholder($key, $agent);
            if ($resolved !== null) {
                return $resolved;
            }

            if (isset($defaults[$key]) && $defaults[$key] !== '') {
                return $defaults[$key];
            }

            return $inlineDefault ?? $m[0];
        }, $template);
    }

    private function resolveBuiltInPlaceholder(string $key, ?Agent $agent): ?string
    {
        $dateFormats = [
            'current_date'    => 'Y-m-d',
            'date'            => 'Y-m-d',
            'current_time'    => 'H:i',
            'time'            => 'H:i',
            'current_datetime' => 'Y-m-d\TH:i',
            'datetime'        => 'Y-m-d\TH:i',
            'day_of_week'     => 'l',
            'day_of_month'    => 'j',
            'month'           => 'F',
            'year'            => 'Y',
        ];
        $agentKeys = ['agent_name' => true, 'user_name' => true];

        if (isset($dateFormats[$key])) {
            $result = date($dateFormats[$key]);
        } elseif ($agent !== null && isset($agentKeys[$key])) {
            $result = $this->resolveAgentPlaceholder($key, $agent);
        } else {
            $result = null;
        }

        return $result;
    }

    private function resolveAgentPlaceholder(string $key, Agent $agent): string
    {
        if ($key === 'agent_name') {
            return $agent->name;
        }

        // $key === 'user_name'
        $resolver = new PrincipalResolver();
        $ownerUserId = $resolver->ownerUserId((int) $agent->principal_id);
        if ($ownerUserId === null) {
            return $key;
        }
        $user = User::find($ownerUserId);
        return $user instanceof User ? ($user->username ?? $key) : $key;
    }
}
