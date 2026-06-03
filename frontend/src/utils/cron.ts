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
  const min = Number.parseInt(minute, 10)
  const hr = Number.parseInt(hour, 10)

  const validate = (result: { frequency: Frequency; fields: any }) => {
    if (result.fields && !Object.values(result.fields).every((v) => Number.isFinite(v))) {
      return { frequency: 'custom', fields: null } as const
    }
    return result
  }

  // Hourly: M H-E/X * * *  OR  M * * * * (every hour)
  const hourlyMatch = /^(\d+)-(\d+)\/(\d+)$/.exec(hour)
  if (dayOfMonth === '*' && dayOfWeek === '*') {
    if (hourlyMatch) {
      return validate({
        frequency: 'hourly',
        fields: {
          interval: Number.parseInt(hourlyMatch[3], 10),
          startHour: Number.parseInt(hourlyMatch[1], 10),
          endHour: Number.parseInt(hourlyMatch[2], 10),
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
  const dailyMatch = /^\*\/(\d+)$/.exec(dayOfMonth)
  if (dailyMatch && hour !== '*' && dayOfWeek === '*') {
    return validate({
      frequency: 'daily',
      fields: {
        interval: Number.parseInt(dailyMatch[1], 10),
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
        day: Number.parseInt(dayOfWeek, 10),
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
        day: Number.parseInt(dayOfMonth, 10),
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

/**
 * Returns the timezone offset in minutes for a given IANA timezone at a specific instant.
 * Handles fractional offsets like +05:30 (India) correctly by computing the exact difference
 * between local time and UTC.
 */
export function getTimezoneOffsetMinutes(timezone: string, instant: Date): number {
  // Get the local time in the target timezone and the corresponding UTC time
  const tzFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  })
  const utcFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: 'UTC',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  })
  const tzParts = tzFormatter.formatToParts(instant)
  const utcParts = utcFormatter.formatToParts(instant)

  const toDate = (parts: Intl.DateTimeFormatPart[]) => {
    const y = parts.find(p => p.type === 'year')!.value
    const mo = parts.find(p => p.type === 'month')!.value
    const d = parts.find(p => p.type === 'day')!.value
    const h = parts.find(p => p.type === 'hour')!.value
    const mi = parts.find(p => p.type === 'minute')!.value
    const s = parts.find(p => p.type === 'second')!.value
    return new Date(`${y}-${mo}-${d}T${h}:${mi}:${s}Z`)
  }

  const tzInstant = toDate(tzParts)
  const utcInstant = toDate(utcParts)

  let offset = Math.round((tzInstant.getTime() - utcInstant.getTime()) / 60000)

  // Some ICU/timezone implementations have edge-case bugs where the formatted
  // day is off by one near midnight boundaries, causing offset to be wrong by
  // ±24 hours. If the result is outside the valid timezone offset range,
  // try adjusting by ±24 hours to find a valid offset.
  if (Math.abs(offset) > 840) {
    // Try adding 24 hours (for negative offsets that should be positive)
    const adjustedPos = offset + 1440
    if (Math.abs(adjustedPos) <= 840) {
      offset = adjustedPos
    } else {
      // Try subtracting 24 hours (for positive offsets that should be negative)
      const adjustedNeg = offset - 1440
      if (Math.abs(adjustedNeg) <= 840) {
        offset = adjustedNeg
      }
    }
  }

  return offset
}
