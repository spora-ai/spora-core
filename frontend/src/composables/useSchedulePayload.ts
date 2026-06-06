/**
 * useSchedulePayload — pure helpers that build the PATCH payload for
 * `POST/PUT /agents/{id}/scheduled-runs` from the wizard form state.
 *
 * `buildComputedCron` chooses the right cron string for the wizard's
 * frequency + sub-fields. `buildOneShotRunAt` produces the ISO-with-offset
 * timestamp the API expects for a one-shot run. `buildSchedulePayload` is
 * the top-level entry point used by the SFC's submit handler.
 */
import {
  buildHourlyCron,
  buildDailyCron,
  buildWeeklyCron,
  buildMonthlyCron,
  getTimezoneOffsetMinutes,
  type Frequency,
} from '@/utils/cron'

/** Build the cron expression for a wizard state. */
export function buildComputedCron(input: {
  mode: 'oneshot' | 'recurring'
  frequency: Frequency
  cronExpression: string
  hourly: { interval: number; startHour: number; endHour: number; minute: number }
  daily: { interval: number; time: string }
  weekly: { day: number; time: string }
  monthly: { day: number; time: string }
}): string {
  if (input.mode === 'oneshot') return ''
  if (input.frequency === 'custom') return input.cronExpression.trim()

  if (input.frequency === 'hourly') {
    return buildHourlyCron({
      interval: input.hourly.interval,
      startHour: input.hourly.startHour,
      endHour: input.hourly.endHour,
      minute: input.hourly.minute,
    })
  }
  if (input.frequency === 'daily') {
    const [h, m] = input.daily.time.split(':').map(Number)
    return buildDailyCron({ interval: input.daily.interval, hour: h, minute: m })
  }
  if (input.frequency === 'weekly') {
    const [h, m] = input.weekly.time.split(':').map(Number)
    return buildWeeklyCron({ day: input.weekly.day, hour: h, minute: m })
  }
  if (input.frequency === 'monthly') {
    const [h, m] = input.monthly.time.split(':').map(Number)
    return buildMonthlyCron({ day: input.monthly.day, hour: h, minute: m })
  }
  return ''
}

/** Build an ISO 8601 timestamp for a one-shot run, given the user's date+time+tz. */
export function buildOneShotRunAt(input: {
  runDate: string
  runTime: string
  timezone: string
}): string {
  const [year, month, day] = input.runDate.split('-').map(Number)
  const [hour, minute] = input.runTime.split(':').map(Number)
  const utcMs = Date.UTC(year, month - 1, day, hour, minute, 0, 0)
  const localDt = new Date(utcMs)
  const offsetMinutes = getTimezoneOffsetMinutes(input.timezone, localDt)
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const abs = Math.abs(offsetMinutes)
  const tzOffsetStr = `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`
  return `${input.runDate}T${input.runTime}:00${tzOffsetStr}`
}

/** Whether the Step 3 form is complete enough to submit. */
export function canSubmitFromStep3(input: {
  mode: 'oneshot' | 'recurring'
  runDate: string
  runTime: string
  computedCron: string
}): boolean {
  if (input.mode === 'oneshot') {
    return !!(input.runDate && input.runTime)
  }
  return !!input.computedCron
}

/** Build the PATCH /agents/{id}/scheduled-runs payload (run_at or cron_expression branch). */
export function buildSchedulePayload(input: {
  timezone: string
  maxStepsOverride: number | null
  mode: 'oneshot' | 'recurring'
  runDate: string
  runTime: string
  computedCron: string
}): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    timezone: input.timezone,
    is_active: true,
  }

  if (input.maxStepsOverride !== null) {
    payload.max_steps_override = input.maxStepsOverride
  }

  if (input.mode === 'oneshot') {
    payload.run_at = buildOneShotRunAt({
      runDate: input.runDate,
      runTime: input.runTime,
      timezone: input.timezone,
    })
  } else {
    payload.cron_expression = input.computedCron
  }

  return payload
}
