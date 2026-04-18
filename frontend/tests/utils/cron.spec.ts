import { describe, it, expect } from 'vitest'
import {
  buildHourlyCron,
  buildDailyCron,
  buildWeeklyCron,
  buildMonthlyCron,
  parseCron,
  DAY_OF_WEEK_OPTIONS,
} from '@/utils/cron'

describe('buildHourlyCron', () => {
  it('builds every hour at minute 0', () => {
    expect(buildHourlyCron({ interval: 1, startHour: 0, endHour: 23, minute: 0 }))
      .toBe('0 0-23/1 * * *')
  })

  it('builds every 2 hours from 07 through 17 at minute 30', () => {
    expect(buildHourlyCron({ interval: 2, startHour: 7, endHour: 17, minute: 30 }))
      .toBe('30 7-17/2 * * *')
  })

  it('handles minute 59 and start hour 0', () => {
    expect(buildHourlyCron({ interval: 3, startHour: 0, endHour: 23, minute: 59 }))
      .toBe('59 0-23/3 * * *')
  })
})

describe('buildDailyCron', () => {
  it('builds every day at 09:00', () => {
    expect(buildDailyCron({ interval: 1, hour: 9, minute: 0 }))
      .toBe('0 9 */1 * *')
  })

  it('builds every 3 days at 14:30', () => {
    expect(buildDailyCron({ interval: 3, hour: 14, minute: 30 }))
      .toBe('30 14 */3 * *')
  })

  it('handles midnight', () => {
    expect(buildDailyCron({ interval: 2, hour: 0, minute: 0 }))
      .toBe('0 0 */2 * *')
  })
})

describe('buildWeeklyCron', () => {
  it('builds every Monday at 09:00', () => {
    expect(buildWeeklyCron({ day: 1, hour: 9, minute: 0 }))
      .toBe('0 9 * * 1')
  })

  it('builds every Sunday at 00:00', () => {
    expect(buildWeeklyCron({ day: 0, hour: 0, minute: 0 }))
      .toBe('0 0 * * 0')
  })

  it('builds every Friday at 18:30', () => {
    expect(buildWeeklyCron({ day: 5, hour: 18, minute: 30 }))
      .toBe('30 18 * * 5')
  })
})

describe('buildMonthlyCron', () => {
  it('builds every 1st at 09:00', () => {
    expect(buildMonthlyCron({ day: 1, hour: 9, minute: 0 }))
      .toBe('0 9 1 * *')
  })

  it('builds every 15th at 14:30', () => {
    expect(buildMonthlyCron({ day: 15, hour: 14, minute: 30 }))
      .toBe('30 14 15 * *')
  })

  it('handles day 31', () => {
    expect(buildMonthlyCron({ day: 31, hour: 0, minute: 0 }))
      .toBe('0 0 31 * *')
  })
})

describe('parseCron', () => {
  describe('hourly', () => {
    it('parses every-hour at minute 0', () => {
      const result = parseCron('0 * * * *')
      expect(result.frequency).toBe('hourly')
      expect(result.fields).toEqual({ interval: 1, startHour: 0, endHour: 23, minute: 0 })
    })

    it('parses every 2 hours from 7 through 17 at minute 30', () => {
      const result = parseCron('30 7-17/2 * * *')
      expect(result.frequency).toBe('hourly')
      expect(result.fields).toEqual({ interval: 2, startHour: 7, endHour: 17, minute: 30 })
    })

    it('returns custom for plain asterisk hour field', () => {
      // "30 */2 * * *" uses step values but without a start/range — not supported by our hourly UI
      const result = parseCron('30 */2 * * *')
      expect(result.frequency).toBe('custom')
    })
  })

  describe('daily', () => {
    it('parses every day at 09:00', () => {
      const result = parseCron('0 9 */1 * *')
      expect(result.frequency).toBe('daily')
      expect(result.fields).toEqual({ interval: 1, hour: 9, minute: 0 })
    })

    it('parses every 3 days at 14:00', () => {
      const result = parseCron('0 14 */3 * *')
      expect(result.frequency).toBe('daily')
      expect(result.fields).toEqual({ interval: 3, hour: 14, minute: 0 })
    })

    it('parses every 7 days at 00:30', () => {
      const result = parseCron('30 0 */7 * *')
      expect(result.frequency).toBe('daily')
      expect(result.fields).toEqual({ interval: 7, hour: 0, minute: 30 })
    })
  })

  describe('weekly', () => {
    it('parses every Monday at 09:00', () => {
      const result = parseCron('0 9 * * 1')
      expect(result.frequency).toBe('weekly')
      expect(result.fields).toEqual({ day: 1, hour: 9, minute: 0 })
    })

    it('parses every Sunday at 00:00', () => {
      const result = parseCron('0 0 * * 0')
      expect(result.frequency).toBe('weekly')
      expect(result.fields).toEqual({ day: 0, hour: 0, minute: 0 })
    })

    it('parses every Saturday at 18:30', () => {
      const result = parseCron('30 18 * * 6')
      expect(result.frequency).toBe('weekly')
      expect(result.fields).toEqual({ day: 6, hour: 18, minute: 30 })
    })

    it('returns custom for comma-separated day lists', () => {
      const result = parseCron('0 9 * * 1,3')
      expect(result.frequency).toBe('custom')
    })
  })

  describe('monthly', () => {
    it('parses every 1st at 09:00', () => {
      const result = parseCron('0 9 1 * *')
      expect(result.frequency).toBe('monthly')
      expect(result.fields).toEqual({ day: 1, hour: 9, minute: 0 })
    })

    it('parses every 15th at 14:30', () => {
      const result = parseCron('30 14 15 * *')
      expect(result.frequency).toBe('monthly')
      expect(result.fields).toEqual({ day: 15, hour: 14, minute: 30 })
    })

    it('parses every 31st at midnight', () => {
      const result = parseCron('0 0 31 * *')
      expect(result.frequency).toBe('monthly')
      expect(result.fields).toEqual({ day: 31, hour: 0, minute: 0 })
    })
  })

  describe('custom / invalid', () => {
    it('returns custom for non-5-field strings', () => {
      expect(parseCron('0 9 * *').frequency).toBe('custom')
      expect(parseCron('0 9 * * * *').frequency).toBe('custom')
      expect(parseCron('').frequency).toBe('custom')
    })

    it('returns custom for expressions not representable by UI fields', () => {
      // list of weekdays
      expect(parseCron('0 9 * * 1,3,5').frequency).toBe('custom')
      // day-of-month range
      expect(parseCron('0 9 1-15 * *').frequency).toBe('custom')
      // hour list
      expect(parseCron('0 9,12 * * 1').frequency).toBe('custom')
    })
  })

  describe('round-trip build → parse', () => {
    it('hourly: build then parse returns equivalent fields', () => {
      const cron = buildHourlyCron({ interval: 2, startHour: 7, endHour: 17, minute: 30 })
      const result = parseCron(cron)
      expect(result.frequency).toBe('hourly')
      expect(result.fields).toEqual({ interval: 2, startHour: 7, endHour: 17, minute: 30 })
    })

    it('daily: build then parse returns equivalent fields', () => {
      const cron = buildDailyCron({ interval: 3, hour: 14, minute: 30 })
      const result = parseCron(cron)
      expect(result.frequency).toBe('daily')
      expect(result.fields).toEqual({ interval: 3, hour: 14, minute: 30 })
    })

    it('weekly: build then parse returns equivalent fields', () => {
      const cron = buildWeeklyCron({ day: 5, hour: 9, minute: 0 })
      const result = parseCron(cron)
      expect(result.frequency).toBe('weekly')
      expect(result.fields).toEqual({ day: 5, hour: 9, minute: 0 })
    })

    it('monthly: build then parse returns equivalent fields', () => {
      const cron = buildMonthlyCron({ day: 15, hour: 14, minute: 30 })
      const result = parseCron(cron)
      expect(result.frequency).toBe('monthly')
      expect(result.fields).toEqual({ day: 15, hour: 14, minute: 30 })
    })
  })
})

describe('DAY_OF_WEEK_OPTIONS', () => {
  it('contains all 7 days starting with Sunday at index 0', () => {
    expect(DAY_OF_WEEK_OPTIONS).toHaveLength(7)
    expect(DAY_OF_WEEK_OPTIONS[0]).toEqual({ value: 0, label: 'Sunday' })
    expect(DAY_OF_WEEK_OPTIONS[6]).toEqual({ value: 6, label: 'Saturday' })
  })
})
