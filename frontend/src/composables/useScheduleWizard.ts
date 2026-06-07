/**
 * useScheduleWizard — constants and tiny helpers shared by the 3-step
 * schedule wizard SFC. Domain helpers (cron stringification, payload
 * building, form state, timezone partitioning) live in the three
 * sibling composables:
 *   - @/composables/useSchedulePayload
 *   - @/composables/useScheduleFormState
 *   - @/composables/useTimezoneList
 */
import type { Frequency } from '@/utils/cron'

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
