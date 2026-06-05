/**
 * useScheduleWizard — pure helpers for SharedScheduleEditor.
 *
 * Owns the cron-stringification, payload building, timezone list, prompt
 * variable registry, and form-reset logic for the 3-step schedule wizard.
 * The SFC keeps the template wiring, template-store dispatch, and submit flow.
 */
import {
  buildHourlyCron,
  buildDailyCron,
  buildWeeklyCron,
  buildMonthlyCron,
  parseCron,
  type Frequency,
} from '@/utils/cron'
import { getTimezoneOffsetMinutes } from '@/utils/cron'
import type { ScheduledRunResource } from '@/types/scheduledRun'

export const SCHEDULE_TOTAL_STEPS = 3

export const SCHEDULE_STEP_LABELS = ['Template', 'Schedule Type', 'Schedule'] as const

export const SCHEDULE_FREQUENCY_OPTIONS: { value: Frequency; label: string }[] = [
  { value: 'hourly', label: 'Hourly' },
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'custom', label: 'Custom cron' },
]

/** Pre-defined prompt variables available in scheduled-run prompt templates. */
export const SCHEDULE_PROMPT_VARIABLES = [
  { token: 'current_date', description: 'ISO date, e.g. 2026-04-15' },
  { token: 'current_time', description: 'ISO time, e.g. 14:30' },
  { token: 'current_datetime', description: 'ISO datetime, e.g. 2026-04-15T14:30' },
  { token: 'agent_name', description: 'Agent display name' },
  { token: 'user_name', description: 'Authenticated user name' },
  { token: 'day_of_week', description: 'Day name, e.g. Wednesday' },
  { token: 'day_of_month', description: 'Day of month, e.g. 15' },
  { token: 'month', description: 'Month name, e.g. April' },
  { token: 'year', description: 'Full year, e.g. 2026' },
] as const

export interface CommonTimezoneSet {
  common: string[]
  rest: string[]
}

/** Split the Intl timezone list into a "common" and a "rest" set, both sorted. */
export function partitionTimezones(
  allTimezones: string[],
  commonZoneValues: Set<string>,
): CommonTimezoneSet {
  const common = allTimezones
    .filter((tz) => commonZoneValues.has(tz))
    .sort((a, b) => a.localeCompare(b))
  const rest = allTimezones
    .filter((tz) => !commonZoneValues.has(tz))
    .sort((a, b) => a.localeCompare(b))
  return { common, rest }
}

/** Default value to seed the timezone field with. */
export function defaultTimezone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

/** Build the default set of IANA timezones (with a small common-first sort). */
export function buildTimezoneList(
  intlTimezones: string[],
  commonZoneValues: Set<string>,
): { value: string; label: string }[] {
  const { common, rest } = partitionTimezones(intlTimezones, commonZoneValues)
  return [
    ...common.map((tz) => ({ value: tz, label: tz })),
    ...rest.map((tz) => ({ value: tz, label: tz })),
  ]
}

/** Wrap a token in `{{...}}`. */
export function wrapPromptVariable(token: string): string {
  return `{{${token}}}`
}

/** Whether the Step 1 → Step 2 button is enabled. */
export function canProceedFromStep1(
  templateId: number | null,
  newTemplateName: string,
): boolean {
  if (templateId === null) return false
  if (templateId === -1) return newTemplateName.trim() !== ''
  return true
}

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

/** Reset the wizard form to its defaults. */
export interface WizardFormState {
  mode: 'oneshot' | 'recurring'
  frequency: Frequency
  cronExpression: string
  runDate: string
  runTime: string
  timezone: string
  hourly: { interval: number; startHour: number; endHour: number; minute: number }
  daily: { interval: number; time: string }
  weekly: { day: number; time: string }
  monthly: { day: number; time: string }
}

export function defaultWizardFormState(): WizardFormState {
  return {
    mode: 'oneshot',
    frequency: 'daily',
    cronExpression: '',
    runDate: '',
    runTime: '',
    timezone: 'UTC',
    hourly: { interval: 1, startHour: 0, endHour: 23, minute: 0 },
    daily: { interval: 1, time: '09:00' },
    weekly: { day: 1, time: '09:00' },
    monthly: { day: 1, time: '09:00' },
  }
}

/** Format a runAt ISO string + timezone into the input-friendly date/time pair. */
export function formatRunAtForInput(
  runAt: string,
  tz: string,
): { date: string; time: string } {
  try {
    const dt = new Date(runAt)
    const dFmt = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    })
    const tFmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: tz,
      hour: '2-digit',
      minute: '2-digit',
    })
    return { date: dFmt.format(dt), time: tFmt.format(dt).slice(0, 5) }
  } catch {
    return { date: '', time: '' }
  }
}

/** Parse a cron expression and project it back onto the wizard form fields. */
export function projectCronToFields(
  cron: string,
): Partial<{
  mode: 'oneshot' | 'recurring'
  frequency: Frequency
  cronExpression: string
  hourly: WizardFormState['hourly']
  daily: WizardFormState['daily']
  weekly: WizardFormState['weekly']
  monthly: WizardFormState['monthly']
}> {
  const result = parseCron(cron)
  const frequency = result.frequency
  const updates: ReturnType<typeof projectCronToFields> = { frequency }
  if (result.fields === null) return updates

  if (result.frequency === 'hourly') {
    const f = result.fields as { minute: number; startHour: number; endHour: number; interval: number }
    updates.hourly = {
      minute: f.minute,
      startHour: f.startHour,
      endHour: f.endHour,
      interval: f.interval,
    }
  } else if (result.frequency === 'daily') {
    const f = result.fields as { interval: number; hour: number; minute: number }
    updates.daily = {
      interval: f.interval,
      time: `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`,
    }
  } else if (result.frequency === 'weekly') {
    const f = result.fields as { day: number; hour: number; minute: number }
    updates.weekly = {
      day: f.day,
      time: `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`,
    }
  } else if (result.frequency === 'monthly') {
    const f = result.fields as { day: number; hour: number; minute: number }
    updates.monthly = {
      day: f.day,
      time: `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`,
    }
  }
  return updates
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

/** Decide which of `run_at` / `cron_expression` to populate on the resource. */
export function isRecurring(data: Partial<ScheduledRunResource> | null | undefined): boolean {
  return !!data?.cron_expression
}
