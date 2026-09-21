<?php

declare(strict_types=1);

use Spora\Tools\ScheduleTool\ScheduleSummaryPresenter;

describe('ScheduleSummaryPresenter', function (): void {
    beforeEach(function (): void {
        $this->summary = new ScheduleSummaryPresenter();
    });

    describe('targetAgentLabel', function (): void {
        test('returns "calling agent" when no agent_id is supplied', function (): void {
            expect($this->summary->targetAgentLabel([]))
                ->toBe('calling agent');
        });

        test('returns "calling agent" when agent_id is non-positive', function (): void {
            expect($this->summary->targetAgentLabel(['agent_id' => 0]))->toBe('calling agent')
                ->and($this->summary->targetAgentLabel(['agent_id' => 'abc']))->toBe('calling agent');
        });

        test('returns "agent #N" for positive numeric agent_id', function (): void {
            expect($this->summary->targetAgentLabel(['agent_id' => 42]))->toBe('agent #42');
        });
    });

    describe('scheduleIdLabel', function (): void {
        test('returns fallback when schedule_id is missing', function (): void {
            expect($this->summary->scheduleIdLabel([]))->toBe('no schedule_id');
        });

        test('returns "schedule #N" when present', function (): void {
            expect($this->summary->scheduleIdLabel(['schedule_id' => 99]))->toBe('schedule #99');
        });
    });

    describe('templateIdLabel', function (): void {
        test('returns fallback when template_id is missing', function (): void {
            expect($this->summary->templateIdLabel([]))->toBe('no template_id');
        });

        test('returns "template #N" when present', function (): void {
            expect($this->summary->templateIdLabel(['template_id' => 17]))->toBe('template #17');
        });
    });

    describe('createSchedule', function (): void {
        test('renders cron + raw_prompt path', function (): void {
            $out = $this->summary->createSchedule([
                'agent_id'         => 3,
                'schedule_payload' => [
                    'cron_expression' => '0 7 * * *',
                    'raw_prompt'      => 'morning brief',
                ],
            ], 'agent #3');
            expect($out)
                ->toContain('agent #3')
                ->toContain('cron "0 7 * * *"')
                ->toContain('raw_prompt');
        });

        test('renders one-shot + template_id path', function (): void {
            $out = $this->summary->createSchedule([
                'agent_id'         => 5,
                'schedule_payload' => [
                    'run_at'      => '2026-12-31T23:59:00+00:00',
                    'template_id' => 11,
                ],
            ], 'agent #5');
            expect($out)
                ->toContain('one-shot "2026-12-31T23:59:00+00:00"')
                ->toContain('template #11');
        });

        test('falls back to "unspecified cadence" / "raw_prompt" labels', function (): void {
            $out = $this->summary->createSchedule([
                'agent_id'         => 0,
                'schedule_payload' => [],
            ], 'calling agent');
            expect($out)
                ->toContain('unspecified cadence')
                ->toContain('raw_prompt');
        });
    });

    describe('createPromptTemplate', function (): void {
        test('renders the template name in quotes', function (): void {
            $out = $this->summary->createPromptTemplate([
                'agent_id'         => 0,
                'template_payload' => ['name' => 'Daily summary'],
            ], 'calling agent');
            expect($out)->toContain('"Daily summary"');
        });

        test('falls back to "(unnamed)" when name is missing', function (): void {
            $out = $this->summary->createPromptTemplate([
                'agent_id'         => 0,
                'template_payload' => [],
            ], 'calling agent');
            expect($out)->toContain('"(unnamed)"');
        });
    });

    describe('resource', function (): void {
        test('recurring schedule row', function (): void {
            $out = $this->summary->resource([
                'scheduled_run' => [
                    'cron_expression' => '0 7 * * *',
                    'template_id'     => 11,
                    'is_active'       => true,
                    'timezone'        => 'Europe/Berlin',
                ],
            ]);
            expect($out)
                ->toContain('cron "0 7 * * *"')
                ->toContain('template #11')
                ->toContain('active')
                ->toContain('Europe/Berlin');
        });

        test('paused one-shot with raw prompt', function (): void {
            $out = $this->summary->resource([
                'scheduled_run' => [
                    'cron_expression' => null,
                    'run_at'          => '2026-12-31T23:59:00+00:00',
                    'template_id'     => null,
                    'raw_prompt'      => 'run once',
                    'is_active'       => false,
                    'timezone'        => 'UTC',
                ],
            ]);
            expect($out)
                ->toContain('one-shot "2026-12-31T23:59:00+00:00"')
                ->toContain('raw_prompt')
                ->toContain('paused')
                ->toContain('UTC');
        });

        test('returns "(empty)" when the payload wraps no array', function (): void {
            expect($this->summary->resource([]))->toBe('(empty)');
        });
    });
});
