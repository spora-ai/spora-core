/**
 * useScheduledRunsTable — pure helpers for ScheduledRunsPage.
 */
import { describe, it, expect } from 'vitest'
import {
  formatScheduleSummary,
  formatRunTimestamp,
  formatScheduleName,
  formatRunCountLabel,
  upsertScheduledRun,
  removeScheduledRun,
} from '@/composables/useScheduledRunsTable'
import type { ScheduledRunResource } from '@/types/scheduledRun'

const baseRun: ScheduledRunResource = {
  id: 1,
  agent_id: 10,
  template_id: null,
  template_name: null,
  raw_prompt: null,
  cron_expression: null,
  run_at: null,
  timezone: 'UTC',
  max_steps_override: null,
  is_active: true,
  next_run_at: null,
  last_run_at: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

describe('useScheduledRunsTable helpers', () => {
  describe('formatScheduleSummary', () => {
    it('returns "Recurring" for a cron expression', () => {
      expect(formatScheduleSummary({ ...baseRun, cron_expression: '* * * * *' })).toBe('Recurring: * * * * *')
    })

    it('returns "One-shot" with a formatted date for a run_at', () => {
      const out = formatScheduleSummary({ ...baseRun, run_at: '2026-04-15T09:00:00Z' })
      expect(out.startsWith('One-shot:')).toBe(true)
    })

    it('returns "Unknown" when neither cron nor run_at present', () => {
      expect(formatScheduleSummary(baseRun)).toBe('Unknown')
    })
  })

  describe('formatRunTimestamp', () => {
    it('returns "—" for null input', () => {
      expect(formatRunTimestamp(null, 'UTC')).toBe('—')
    })

    it('formats a valid ISO timestamp', () => {
      const out = formatRunTimestamp('2026-04-15T09:00:00Z', 'UTC')
      expect(out.length).toBeGreaterThan(0)
    })
  })

  describe('formatScheduleName', () => {
    it('returns template_name when present', () => {
      expect(formatScheduleName({ ...baseRun, template_name: 'Welcome' })).toBe('Welcome')
    })

    it('falls back to "Template #ID" when only template_id is set', () => {
      expect(formatScheduleName({ ...baseRun, template_id: 5 })).toBe('Template #5')
    })

    it('returns a "Custom: ..." snippet for raw_prompt', () => {
      const out = formatScheduleName({ ...baseRun, raw_prompt: 'Do something quick' })
      expect(out).toBe('Custom: Do something quick')
    })

    it('truncates long raw_prompt snippets', () => {
      const long = 'x'.repeat(100)
      const out = formatScheduleName({ ...baseRun, raw_prompt: long })
      expect(out.endsWith('…')).toBe(true)
    })

    it('falls back to "Scheduled run" when no name source', () => {
      expect(formatScheduleName(baseRun)).toBe('Scheduled run')
    })
  })

  describe('formatRunCountLabel', () => {
    it('pluralizes correctly', () => {
      expect(formatRunCountLabel(0)).toBe('0 scheduled runs')
      expect(formatRunCountLabel(1)).toBe('1 scheduled run')
      expect(formatRunCountLabel(2)).toBe('2 scheduled runs')
    })
  })

  describe('upsertScheduledRun', () => {
    it('prepends a new run when id not in list', () => {
      const runs = [{ ...baseRun, id: 1 }]
      const out = upsertScheduledRun(runs, { ...baseRun, id: 2 })
      expect(out[0].id).toBe(2)
    })

    it('replaces the row when id exists', () => {
      const runs = [{ ...baseRun, id: 1, raw_prompt: 'a' }]
      const out = upsertScheduledRun(runs, { ...baseRun, id: 1, raw_prompt: 'b' })
      expect(out).toHaveLength(1)
      expect(out[0].raw_prompt).toBe('b')
    })

    it('returns the original list when id is falsy', () => {
      const runs = [baseRun]
      const out = upsertScheduledRun(runs, { id: 0 } as { id: number } & Partial<ScheduledRunResource>)
      expect(out).toBe(runs)
    })
  })

  describe('removeScheduledRun', () => {
    it('drops the row with the given id', () => {
      const runs = [
        { ...baseRun, id: 1 },
        { ...baseRun, id: 2 },
      ]
      const out = removeScheduledRun(runs, 1)
      expect(out).toHaveLength(1)
      expect(out[0].id).toBe(2)
    })

    it('returns the list unchanged when id is absent', () => {
      const runs = [{ ...baseRun, id: 1 }]
      const out = removeScheduledRun(runs, 99)
      expect(out).toEqual(runs)
    })
  })
})
