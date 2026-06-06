<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Owns the scheduled-run lifecycle: claim next due entry, build the prompt,
 * start the orchestrator, mark the entry DONE, and queue the next occurrence.
 */
final class ScheduledRunProcessor
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** Tracks how many scheduled runs were processed in the last process() call (testing hook). */
    public int $lastProcessed = 0;

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherInterface $mercure,
        private readonly NotificationService $notificationService,
    ) {}

    public function process(OutputInterface $output): void
    {
        $context = $this->claimNextScheduledRun();
        if ($context === null) {
            return;
        }

        $entry = $context['entry'];
        $run = $context['run'];
        $agent = $context['agent'];

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
        }

        $prompt = $this->buildPrompt($run, $template, $agent);
        $maxSteps = $this->resolveMaxSteps($run, $template, $agent);

        $this->logger->info('Triggering scheduled run', [
            'run_id' => $run->id,
            'agent_id' => $run->agent_id,
        ]);
        $output->writeln(sprintf('<info>Triggering scheduled run %d for agent %d...</info>', $run->id, $run->agent_id));

        try {
            $task = $this->orchestrator->start((int) $run->agent_id, $prompt, (int) $maxSteps, null, $run->id);
        } catch (Throwable $e) {
            $this->logger->error('Scheduled run failed', [
                'run_id' => $run->id,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
            $output->writeln(sprintf('<error>Scheduled run %d failed: %s</error>', $run->id, $e->getMessage()));
            return;
        }

        $this->finalizeScheduledRun($run, $entry, $task);
        $this->lastProcessed = 1;
    }

    /**
     * Atomically claim the next due scheduled run and resolve its run/agent.
     *
     * @return array{entry: object, run: ScheduledRun, agent: Agent}|null
     */
    private function claimNextScheduledRun(): ?array
    {
        $now = date(self::DB_DATETIME_FORMAT);

        $claimed = Capsule::table('scheduled_runs_next')
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->where('due_at', '<=', $now)
            ->limit(1)
            ->update([
                'status'     => ScheduledRunNext::STATUS_CLAIMED,
                'claimed_at' => $now,
            ]);

        if ($claimed <= 0) {
            return null;
        }

        $entry = Capsule::table('scheduled_runs_next')
            ->where('status', ScheduledRunNext::STATUS_CLAIMED)
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->first();

        if ($entry === null) {
            return null;
        }

        return $this->resolveClaimedRun($entry);
    }

    /**
     * @return array{entry: object, run: ScheduledRun, agent: Agent}|null
     */
    private function resolveClaimedRun(object $entry): ?array
    {
        /** @var ScheduledRun|null $run */
        $run = ScheduledRun::find((int) $entry->scheduled_run_id);

        if ($run === null || !$run->is_active) {
            $this->markScheduledRunSkipped((int) $entry->id);
            return null;
        }

        $agent = Agent::find($run->agent_id);
        if ($agent === null) {
            $this->logger->warning('Scheduled run has no agent, skipping', ['run_id' => $run->id]);
            $this->markScheduledRunSkipped((int) $entry->id);
            return null;
        }

        return ['entry' => $entry, 'run' => $run, 'agent' => $agent];
    }

    private function markScheduledRunSkipped(int $entryId): void
    {
        Capsule::table('scheduled_runs_next')
            ->where('id', $entryId)
            ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
    }

    private function buildPrompt(ScheduledRun $run, ?AgentPromptTemplate $template, Agent $agent): string
    {
        if ($template !== null) {
            $variables = $template->variables ?? [];
            return $this->substituteVariables($template->prompt_template ?? '', $variables, $agent);
        }

        $prompt = $run->raw_prompt ?? '';
        return $this->substituteVariables($prompt, [], $agent);
    }

    private function resolveMaxSteps(ScheduledRun $run, ?AgentPromptTemplate $template, Agent $agent): int
    {
        if ($run->max_steps_override !== null) {
            return (int) $run->max_steps_override;
        }
        if ($template !== null && $template->max_steps !== null) {
            return (int) $template->max_steps;
        }
        return (int) $agent->max_steps;
    }

    /**
     * Mark the claimed entry DONE, schedule the next run, and publish notifications.
     */
    private function finalizeScheduledRun(ScheduledRun $run, object $entry, Task $task): void
    {
        $completedAt = date(self::DB_DATETIME_FORMAT);
        $nextDueAt = $this->computeNextDueAt($run);

        // Atomically mark DONE and insert next PENDING entry (if recurring).
        // This prevents the gap where the old entry is CLAIMED/DONE but the next entry
        // was never created (e.g. process crash or signal interruption between steps).
        Capsule::connection()->transaction(function () use ($run, $entry, $completedAt, $nextDueAt): void {
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update([
                    'status'       => ScheduledRunNext::STATUS_DONE,
                    'completed_at' => $completedAt,
                ]);

            if ($nextDueAt !== null) {
                $this->insertRecurringEntry($run, $nextDueAt, $completedAt);
                $this->updateRecurringRun($run, $completedAt, $nextDueAt);
            } else {
                $this->deactivateRun($run, $completedAt);
            }
        });

        $this->notificationService->notifyScheduledRunCompleted($run->id, $task);
        $this->notificationService->sendEmailForScheduledRun($task);

        $taskData = [
            'id'          => $task->id,
            'agent_id'    => $task->agent_id,
            'status'      => $task->status,
            'user_prompt' => $task->user_prompt,
        ];
        $this->mercure->publish($task->id, $task->user_id, $taskData);
    }

    private function computeNextDueAt(ScheduledRun $run): ?string
    {
        if ($run->cron_expression === null) {
            return null;
        }

        $nowInScheduleTz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($run->timezone));

        return (new CronExpression($run->cron_expression))
            ->getNextRunDate($nowInScheduleTz, 0, false, $run->timezone)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DB_DATETIME_FORMAT);
    }

    private function insertRecurringEntry(ScheduledRun $run, string $nextDueAt, string $completedAt): void
    {
        Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('due_at', $nextDueAt)
            ->whereIn('status', [ScheduledRunNext::STATUS_PENDING, ScheduledRunNext::STATUS_CLAIMED])
            ->delete();

        Capsule::connection()->statement(
            "INSERT OR IGNORE INTO scheduled_runs_next (scheduled_run_id, due_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            [$run->id, $nextDueAt, ScheduledRunNext::STATUS_PENDING, $completedAt, $completedAt],
        );
    }

    private function updateRecurringRun(ScheduledRun $run, string $completedAt, string $nextDueAt): void
    {
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update([
                'last_run_at' => $completedAt,
                'next_run_at' => $nextDueAt,
            ]);
    }

    private function deactivateRun(ScheduledRun $run, string $completedAt): void
    {
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update([
                'last_run_at' => $completedAt,
                'next_run_at' => null,
                'is_active'   => 0,
            ]);
    }

    /**
     * Substitute {{variable}} placeholders in a template string.
     */
    private function substituteVariables(string $template, array $variables, ?Agent $agent = null): string
    {
        // Convert the JSON list to a map of key => default_value
        $defaults = [];
        foreach ($variables as $v) {
            if (isset($v['key'])) {
                $defaults[$v['key']] = $v['default_value'] ?? null;
            }
        }

        return preg_replace_callback('/\{\{(\w+)(?::([^}]*))?\}\}/', function (array $m) use ($defaults, $agent): string {
            return $this->resolveTemplateVariable($m, $defaults, $agent);
        }, $template);
    }

    /**
     * @param array<string, string|null> $defaults
     */
    private function resolveTemplateVariable(array $match, array $defaults, ?Agent $agent): string
    {
        $key = $match[1];
        $inlineDefault = $match[2] ?? null;

        $builtin = match (true) {
            $key === 'current_date' || $key === 'date'     => date('Y-m-d'),
            $key === 'current_time' || $key === 'time'     => date('H:i'),
            $key === 'current_datetime' || $key === 'datetime' => date('Y-m-d\TH:i'),
            $key === 'day_of_week'    => date('l'),
            $key === 'day_of_month'   => date('j'),
            $key === 'month'          => date('F'),
            $key === 'year'           => date('Y'),
            default                   => null,
        };
        if ($builtin !== null) {
            return $builtin;
        }

        if ($key === 'agent_name' && $agent !== null) {
            return $agent->name;
        }

        if ($key === 'user_name' && $agent !== null) {
            $user = \Spora\Models\User::find($agent->user_id);
            return $user instanceof \Spora\Models\User ? ($user->username ?? $key) : $key;
        }

        if (isset($defaults[$key]) && $defaults[$key] !== '') {
            return (string) $defaults[$key];
        }

        return $inlineDefault ?? $match[0];
    }
}
