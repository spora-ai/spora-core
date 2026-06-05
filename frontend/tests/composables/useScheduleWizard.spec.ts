/**
 * useScheduleWizard — pure helpers for the schedule wizard.
 */
import { describe, it, expect } from 'vitest'
import {
  SCHEDULE_TOTAL_STEPS,
  SCHEDULE_STEP_LABELS,
  SCHEDULE_FREQUENCY_OPTIONS,
  SCHEDULE_PROMPT_VARIABLES,
  partitionTimezones,
  defaultTimezone,
  buildTimezoneList,
  wrapPromptVariable,
  canProceedFromStep1,
  buildComputedCron,
  canSubmitFromStep3,
  defaultWizardFormState,
  formatRunAtForInput,
  projectCronToFields,
  buildOneShotRunAt,
  buildSchedulePayload,
  isRecurring,
} from '@/composables/useScheduleWizard'

describe('useScheduleWizard helpers', () => {
  describe('constants', () => {
    it('exposes total steps and labels', () => {
      expect(SCHEDULE_TOTAL_STEPS).toBe(3)
      expect(SCHEDULE_STEP_LABELS).toHaveLength(3)
    })

    it('exposes frequency options including custom', () => {
      const values = SCHEDULE_FREQUENCY_OPTIONS.map(o => o.value)
      expect(values).toContain('hourly')
      expect(values).toContain('custom')
    })

    it('exposes prompt variables', () => {
      const tokens = SCHEDULE_PROMPT_VARIABLES.map(p => p.token)
      expect(tokens).toContain('current_date')
      expect(tokens).toContain('agent_name')
    })
  })

  describe('partitionTimezones', () => {
    it('splits common vs rest and sorts each', () => {
      const all = ['Asia/Tokyo', 'Europe/London', 'America/New_York']
      const common = new Set(['Europe/London'])
      const { common: c, rest } = partitionTimezones(all, common)
      expect(c).toEqual(['Europe/London'])
      expect(rest).toEqual(['America/New_York', 'Asia/Tokyo'])
    })
  })

  describe('defaultTimezone', () => {
    it('returns a non-empty timezone string', () => {
      const tz = defaultTimezone()
      expect(typeof tz).toBe('string')
      expect(tz.length).toBeGreaterThan(0)
    })
  })

  describe('buildTimezoneList', () => {
    it('builds a flat list with common first', () => {
      const all = ['Asia/Tokyo', 'Europe/London', 'America/New_York']
      const common = new Set(['Europe/London'])
      const list = buildTimezoneList(all, common)
      expect(list.map(x => x.value)).toEqual(['Europe/London', 'America/New_York', 'Asia/Tokyo'])
    })
  })

  describe('wrapPromptVariable', () => {
    it('wraps a token in {{...}}', () => {
      expect(wrapPromptVariable('current_date')).toBe('{{current_date}}')
    })
  })

  describe('canProceedFromStep1', () => {
    it('false when templateId is null', () => {
      expect(canProceedFromStep1(null, '')).toBe(false)
    })

    it('requires a name when templateId === -1 (new)', () => {
      expect(canProceedFromStep1(-1, '')).toBe(false)
      expect(canProceedFromStep1(-1, '   ')).toBe(false)
      expect(canProceedFromStep1(-1, 'Daily')).toBe(true)
    })

    it('true for any other template id', () => {
      expect(canProceedFromStep1(42, '')).toBe(true)
    })
  })

  describe('buildComputedCron', () => {
    const base = {
      mode: 'recurring' as const,
      frequency: 'daily' as const,
      cronExpression: '',
      hourly: { interval: 1, startHour: 0, endHour: 23, minute: 0 },
      daily: { interval: 1, time: '09:00' },
      weekly: { day: 1, time: '09:00' },
      monthly: { day: 1, time: '09:00' },
    }

    it('returns "" for oneshot mode', () => {
      expect(buildComputedCron({ ...base, mode: 'oneshot' })).toBe('')
    })

    it('trims and returns custom cron expression', () => {
      expect(buildComputedCron({ ...base, frequency: 'custom', cronExpression: '  * * * * *  ' })).toBe('* * * * *')
    })

    it('builds an hourly cron string', () => {
      const cron = buildComputedCron({ ...base, frequency: 'hourly' })
      expect(cron.length).toBeGreaterThan(0)
    })

    it('builds daily/weekly/monthly cron strings', () => {
      expect(buildComputedCron({ ...base, frequency: 'daily' }).length).toBeGreaterThan(0)
      expect(buildComputedCron({ ...base, frequency: 'weekly' }).length).toBeGreaterThan(0)
      expect(buildComputedCron({ ...base, frequency: 'monthly' }).length).toBeGreaterThan(0)
    })
  })

  describe('canSubmitFromStep3', () => {
    it('oneshot requires both date and time', () => {
      expect(canSubmitFromStep3({ mode: 'oneshot', runDate: '', runTime: '09:00', computedCron: '' })).toBe(false)
      expect(canSubmitFromStep3({ mode: 'oneshot', runDate: '2026-01-01', runTime: '', computedCron: '' })).toBe(false)
      expect(canSubmitFromStep3({ mode: 'oneshot', runDate: '2026-01-01', runTime: '09:00', computedCron: '' })).toBe(true)
    })

    it('recurring requires a computed cron expression', () => {
      expect(canSubmitFromStep3({ mode: 'recurring', runDate: '', runTime: '', computedCron: '' })).toBe(false)
      expect(canSubmitFromStep3({ mode: 'recurring', runDate: '', runTime: '', computedCron: '* * * * *' })).toBe(true)
    })
  })

  describe('defaultWizardFormState', () => {
    it('has sensible defaults', () => {
      const s = defaultWizardFormState()
      expect(s.mode).toBe('oneshot')
      expect(s.frequency).toBe('daily')
      expect(s.timezone).toBe('UTC')
      expect(s.hourly.interval).toBe(1)
    })
  })

  describe('formatRunAtForInput', () => {
    it('returns date/time pair for a valid ISO + tz', () => {
      const out = formatRunAtForInput('2026-04-15T14:30:00Z', 'UTC')
      expect(out.date).toBe('2026-04-15')
      expect(out.time).toBe('14:30')
    })

    it('returns empty pair on bad input', () => {
      const out = formatRunAtForInput('not-a-date', 'UTC')
      expect(out.date).toBe('')
      expect(out.time).toBe('')
    })
  })

  describe('projectCronToFields', () => {
    it('projects daily cron into daily fields', () => {
      const updates = projectCronToFields('30 9 */1 * *')
      expect(updates.frequency).toBe('daily')
      expect(updates.daily?.time).toBe('09:30')
    })

    it('returns just frequency for custom/unparseable cron', () => {
      const updates = projectCronToFields('this-is-not-cron')
      expect(updates.frequency).toBeDefined()
    })
  })

  describe('buildOneShotRunAt', () => {
    it('returns ISO 8601 with timezone offset for UTC', () => {
      const out = buildOneShotRunAt({ runDate: '2026-04-15', runTime: '09:00', timezone: 'UTC' })
      expect(out).toMatch(/2026-04-15T09:00:00[+-]\d{2}:\d{2}/)
    })
  })

  describe('buildSchedulePayload', () => {
    it('includes run_at for oneshot', () => {
      const payload = buildSchedulePayload({
        timezone: 'UTC',
        maxStepsOverride: null,
        mode: 'oneshot',
        runDate: '2026-01-01',
        runTime: '09:00',
        computedCron: '',
      })
      expect(payload.run_at).toBeDefined()
      expect(payload.cron_expression).toBeUndefined()
      expect(payload.is_active).toBe(true)
    })

    it('includes cron_expression for recurring', () => {
      const payload = buildSchedulePayload({
        timezone: 'UTC',
        maxStepsOverride: 5,
        mode: 'recurring',
        runDate: '',
        runTime: '',
        computedCron: '0 9 * * *',
      })
      expect(payload.cron_expression).toBe('0 9 * * *')
      expect(payload.run_at).toBeUndefined()
      expect(payload.max_steps_override).toBe(5)
    })

    it('omits max_steps_override when null', () => {
      const payload = buildSchedulePayload({
        timezone: 'UTC',
        maxStepsOverride: null,
        mode: 'recurring',
        runDate: '',
        runTime: '',
        computedCron: '* * * * *',
      })
      expect(payload.max_steps_override).toBeUndefined()
    })
  })

  describe('isRecurring', () => {
    it('true when cron_expression is set', () => {
      expect(isRecurring({ cron_expression: '* * * * *' })).toBe(true)
    })

    it('false otherwise', () => {
      expect(isRecurring({})).toBe(false)
      expect(isRecurring(null)).toBe(false)
      expect(isRecurring(undefined)).toBe(false)
    })
  })
})
