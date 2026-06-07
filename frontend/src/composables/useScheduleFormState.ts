/**
 * useScheduleFormState — default + projection helpers for the 3-step schedule wizard.
 *
 * `defaultWizardFormState` seeds the form on open. `projectCronToFields` is the
 * inverse of `useSchedulePayload.buildComputedCron` — used when editing an
 * existing scheduled run. `formatRunAtForInput` renders a one-shot run-at ISO
 * string into the two HTML inputs (date + time) the wizard shows.
 */
import { parseCron } from '@/utils/cron'
import type { Frequency } from '@/utils/cron'
import type { ScheduledRunResource } from '@/types/scheduledRun'

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

/** Decide which of `run_at` / `cron_expression` to populate on the resource. */
export function isRecurring(data: Partial<ScheduledRunResource> | null | undefined): boolean {
  return !!data?.cron_expression
}
