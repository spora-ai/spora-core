import { describe, it, expect } from 'vitest'
import {
  buildComputedCron,
  canSubmitFromStep3,
  buildOneShotRunAt,
  buildSchedulePayload,
} from '@/composables/useSchedulePayload'

const baseForm = {
  mode: 'recurring' as const,
  frequency: 'daily' as const,
  cronExpression: '',
  hourly: { interval: 1, startHour: 0, endHour: 23, minute: 0 },
  daily: { interval: 1, time: '09:00' },
  weekly: { day: 1, time: '09:00' },
  monthly: { day: 1, time: '09:00' },
}

describe('useSchedulePayload', () => {
  describe('buildComputedCron', () => {
    it('returns "" for oneshot mode', () => {
      expect(buildComputedCron({ ...baseForm, mode: 'oneshot' })).toBe('')
    })

    it('trims and returns custom cron expression', () => {
      expect(buildComputedCron({ ...baseForm, frequency: 'custom', cronExpression: '  * * * * *  ' })).toBe('* * * * *')
    })

    it('builds an hourly cron string', () => {
      const cron = buildComputedCron({ ...baseForm, frequency: 'hourly' })
      expect(cron.length).toBeGreaterThan(0)
    })

    it('builds daily/weekly/monthly cron strings', () => {
      expect(buildComputedCron({ ...baseForm, frequency: 'daily' }).length).toBeGreaterThan(0)
      expect(buildComputedCron({ ...baseForm, frequency: 'weekly' }).length).toBeGreaterThan(0)
      expect(buildComputedCron({ ...baseForm, frequency: 'monthly' }).length).toBeGreaterThan(0)
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
})
