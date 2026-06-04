/**
 * useScheduledRunsTable — pure helpers for ScheduledRunsPage.
 *
 * Owns the table's display formatters (schedule summary, timestamps,
 * display name with truncation) and the runs-list mutation helpers.
 */
import type { ScheduledRunResource } from '@/types/scheduledRun'

/** Human label for the recurring/one-shot column. */
export function formatScheduleSummary(run: ScheduledRunResource): string {
  if (run.cron_expression) {
    return `Recurring: ${run.cron_expression}`
  }
  if (run.run_at) {
    try {
      return `One-shot: ${new Date(run.run_at).toLocaleString(undefined, { timeZone: run.timezone })}`
    } catch {
      return 'One-shot'
    }
  }
  return 'Unknown'
}

/** Format a stored ISO timestamp in the run's own timezone. */
export function formatRunTimestamp(iso: string | null, tz: string): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { timeZone: tz })
  } catch {
    return iso
  }
}

const SNIPPET_MAX = 40

/** Human label for a scheduled run's name column. */
export function formatScheduleName(run: ScheduledRunResource): string {
  if (run.template_name) return run.template_name
  if (run.template_id) return `Template #${run.template_id}`
  if (run.raw_prompt) {
    const snippet =
      run.raw_prompt.length > SNIPPET_MAX
        ? run.raw_prompt.slice(0, SNIPPET_MAX) + '…'
        : run.raw_prompt
    return `Custom: ${snippet}`
  }
  return 'Scheduled run'
}

/** Pluralized header label. */
export function formatRunCountLabel(count: number): string {
  return `${count} scheduled run${count !== 1 ? 's' : ''}`
}

/** Replace the row matching `saved.id` in `runs`, or prepend it. */
export function upsertScheduledRun(
  runs: ScheduledRunResource[],
  saved: ScheduledRunResource | { id: number } & Partial<ScheduledRunResource>,
): ScheduledRunResource[] {
  if (!saved.id) return runs
  const idx = runs.findIndex((r) => r.id === saved.id)
  if (idx === -1) {
    return [saved as ScheduledRunResource, ...runs]
  }
  const next = runs.slice()
  next[idx] = { ...runs[idx], ...saved } as ScheduledRunResource
  return next
}

/** Drop the run with the given id. */
export function removeScheduledRun(
  runs: ScheduledRunResource[],
  id: number,
): ScheduledRunResource[] {
  return runs.filter((r) => r.id !== id)
}
