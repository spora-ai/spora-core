/**
 * Cron expression builder and parser utilities.
 *
 * Format used: standard 5-field cron (minute hour day-of-month month day-of-week)
 *
 * day-of-week: 0=Sunday, 1=Monday, ..., 6=Saturday
 */

export type Frequency = 'hourly' | 'daily' | 'weekly' | 'monthly' | 'custom'

export interface HourlyFields {
  interval: number    // every X hours (1–23)
  startHour: number  // start hour (0–23)
  endHour: number    // end hour (0–23), runs startHour, startHour+interval, ... endHour
  minute: number      // at minute (0–59)
}

export interface DailyFields {
  interval: number    // every X days (1–31)
  hour: number        // at hour (0–23)
  minute: number      // at minute (0–59)
}

export interface WeeklyFields {
  day: number        // day of week: 0=Sun … 6=Sat
  hour: number        // at hour (0–23)
  minute: number      // at minute (0–59)
}

export interface MonthlyFields {
  day: number        // day of month (1–31)
  hour: number        // at hour (0–23)
  minute: number      // at minute (0–59)
}

/** Build a cron expression from hourly fields. */
export function buildHourlyCron(fields: HourlyFields): string {
  return `${fields.minute} ${fields.startHour}-${fields.endHour}/${fields.interval} * * *`
}

/** Build a cron expression from daily fields. */
export function buildDailyCron(fields: DailyFields): string {
  return `${fields.minute} ${fields.hour} */${fields.interval} * *`
}

/** Build a cron expression from weekly fields. */
export function buildWeeklyCron(fields: WeeklyFields): string {
  return `${fields.minute} ${fields.hour} * * ${fields.day}`
}

/** Build a cron expression from monthly fields. */
export function buildMonthlyCron(fields: MonthlyFields): string {
  return `${fields.minute} ${fields.hour} ${fields.day} * *`
}

/**
 * Parse a cron expression into typed fields.
 * Returns null for frequencies that can't be represented by the UI fields
 * (e.g., complex expressions with lists or ranges in unexpected positions).
 */
export function parseCron(
  cron: string,
): { frequency: Frequency; fields: HourlyFields | DailyFields | WeeklyFields | MonthlyFields | null } {
  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) return { frequency: 'custom', fields: null }

  const [minute, hour, dayOfMonth, , dayOfWeek] = parts
  const min = parseInt(minute, 10)
  const hr = parseInt(hour, 10)

  const validate = (result: { frequency: Frequency; fields: any }) => {
    if (result.fields && !Object.values(result.fields).every((v) => Number.isFinite(v))) {
      return { frequency: 'custom', fields: null } as const
    }
    return result as { frequency: Frequency; fields: any }
  }

  // Hourly: M H-E/X * * *  OR  M * * * * (every hour)
  const hourlyMatch = hour.match(/^(\d+)-(\d+)\/(\d+)$/)
  if (dayOfMonth === '*' && dayOfWeek === '*') {
    if (hourlyMatch) {
      return validate({
        frequency: 'hourly',
        fields: {
          interval: parseInt(hourlyMatch[3], 10),
          startHour: parseInt(hourlyMatch[1], 10),
          endHour: parseInt(hourlyMatch[2], 10),
          minute: min,
        },
      })
    }
    if (hour === '*') {
      return validate({
        frequency: 'hourly',
        fields: { interval: 1, startHour: 0, endHour: 23, minute: min },
      })
    }
  }

  // Daily: M H */X * *
  const dailyMatch = dayOfMonth.match(/^\*\/(\d+)$/)
  if (dailyMatch && hour !== '*' && dayOfWeek === '*') {
    return validate({
      frequency: 'daily',
      fields: {
        interval: parseInt(dailyMatch[1], 10),
        hour: hr,
        minute: min,
      },
    })
  }

  // Weekly: M H * * D
  if (dayOfMonth === '*' && dayOfWeek !== '*' && !dayOfWeek.includes(',') && !hour.includes(',')) {
    return validate({
      frequency: 'weekly',
      fields: {
        day: parseInt(dayOfWeek, 10),
        hour: hr,
        minute: min,
      },
    })
  }

  // Monthly: M H D * *
  if (dayOfMonth !== '*' && /^\d+$/.test(dayOfMonth) && dayOfWeek === '*') {
    return validate({
      frequency: 'monthly',
      fields: {
        day: parseInt(dayOfMonth, 10),
        hour: hr,
        minute: min,
      },
    })
  }

  return { frequency: 'custom', fields: null }
}

export const DAY_OF_WEEK_OPTIONS = [
  { value: 0, label: 'Sunday' },
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
] as const
