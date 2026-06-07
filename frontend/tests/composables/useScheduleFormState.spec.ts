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

    it('returns just frequency when fields are null (custom expression)', () => {
      // 6 fields → "custom" frequency with null fields; only frequency is set.
      const updates = projectCronToFields('0 9 * * 1,3,5 *')
      expect(updates.frequency).toBe('custom')
      expect(updates.hourly).toBeUndefined()
      expect(updates.daily).toBeUndefined()
      expect(updates.weekly).toBeUndefined()
      expect(updates.monthly).toBeUndefined()
    })

    it('projects weekly cron into weekly fields', () => {
      // 0 9 * * 1 = Mondays at 09:00
      const updates = projectCronToFields('0 9 * * 1')
      expect(updates.frequency).toBe('weekly')
      expect(updates.weekly?.day).toBe(1)
      expect(updates.weekly?.time).toBe('09:00')
    })

    it('projects weekly cron with non-zero minute and pads the time', () => {
      // 5 14 * * 5 = Fridays at 14:05
      const updates = projectCronToFields('5 14 * * 5')
      expect(updates.frequency).toBe('weekly')
      expect(updates.weekly?.day).toBe(5)
      expect(updates.weekly?.time).toBe('14:05')
    })

    it('projects monthly cron into monthly fields', () => {
      // 0 9 15 * * = 15th of the month at 09:00
      const updates = projectCronToFields('0 9 15 * *')
      expect(updates.frequency).toBe('monthly')
      expect(updates.monthly?.day).toBe(15)
      expect(updates.monthly?.time).toBe('09:00')
    })

    it('projects monthly cron with non-zero minute and pads the time', () => {
      // 30 23 1 * * = 1st of the month at 23:30
      const updates = projectCronToFields('30 23 1 * *')
      expect(updates.frequency).toBe('monthly')
      expect(updates.monthly?.day).toBe(1)
      expect(updates.monthly?.time).toBe('23:30')
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
