import { describe, it, expect } from 'vitest'
import {
  defaultWizardFormState,
  formatRunAtForInput,
  projectCronToFields,
  isRecurring,
} from '@/composables/useScheduleFormState'

describe('useScheduleFormState', () => {
  describe('defaultWizardFormState', () => {
    it('has sensible defaults', () => {
      const s = defaultWizardFormState()
      expect(s.mode).toBe('oneshot')
      expect(s.frequency).toBe('daily')
      expect(s.timezone).toBe('UTC')
      expect(s.hourly.interval).toBe(1)
      expect(s.daily.time).toBe('09:00')
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

    it('projects hourly cron into hourly fields', () => {
      const updates = projectCronToFields('15 7-17/2 * * *')
      expect(updates.frequency).toBe('hourly')
      expect(updates.hourly?.interval).toBe(2)
      expect(updates.hourly?.startHour).toBe(7)
      expect(updates.hourly?.endHour).toBe(17)
      expect(updates.hourly?.minute).toBe(15)
    })

    it('returns just frequency for custom/unparseable cron', () => {
      const updates = projectCronToFields('this-is-not-cron')
      expect(updates.frequency).toBeDefined()
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
