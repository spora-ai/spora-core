/**
 * useTaskChat — pure helpers for the TaskChatPage.
 *
 * Extracts derived state and small formatters so the SFC keeps only the
 * template and the wiring to the task store. Everything in this file takes
 * inputs and returns values — no Vue lifecycle, no DOM, no store calls.
 */
import type { HistoryEntry } from '@/types/task'

export const RETRYABLE_ERROR_CODES = [
  'RATE_LIMIT',
  'SERVER_OVERLOADED',
  'SERVER_ERROR',
  'GATEWAY_ERROR',
  'AUTH_ERROR',
  'LLM_TIMEOUT',
  'ORPHANED',
] as const

export const NON_RETRYABLE_ERROR_CODES = [
  'NO_LLM_CONFIGURATION',
  'UNKNOWN',
] as const

export type RetryableErrorCode = typeof RETRYABLE_ERROR_CODES[number]
export type NonRetryableErrorCode = typeof NON_RETRYABLE_ERROR_CODES[number]

export interface RetryState {
  /** Set when the task is in a retry chain. */
  isRetryTask: boolean
  /** Whether the agent has auto-retry configured. */
  autoRetryConfigured: boolean
  /** 1-indexed attempt counter. */
  retryAttempt: number
  /** Maximum number of retries configured. */
  maxRetryAttempts: number
  /** True when retry_after is set AND more retries remain. */
  canAutoRetry: boolean
  /** True when retry_after is set AND no more retries remain. */
  retriesExhausted: boolean
  /** True when retry_after is set AND max_retries is 0 (never fires). */
  autoRetryDisabled: boolean
}

/** Format the ms-until-retry as a "m:ss" countdown. */
export function formatCountdown(retryAfterIso: string | null | undefined): string {
  if (!retryAfterIso) return ''
  const ms = Math.max(0, new Date(retryAfterIso).getTime() - Date.now())
  if (ms <= 0) return '0:00'
  const m = Math.floor(ms / 60000)
  const s = Math.floor((ms % 60000) / 1000)
  return `${m}:${s.toString().padStart(2, '0')}`
}

/** Truncate text to a max length, appending an ellipsis if needed. */
export function truncateText(text: string | null, max = 300): string {
  if (!text) return '(empty)'
  return text.length <= max ? text : text.slice(0, max) + '…'
}

/** True when text is longer than the max length. */
export function isTruncated(text: string | null, max = 300): boolean {
  return text !== null && text.length > max
}

/** Compute retry-related flags from a task snapshot. */
export function computeRetryState(
  retryOfTaskId: number | null | undefined,
  maxRetries: number | null | undefined,
  retryCount: number | null | undefined,
): RetryState {
  const isRetryTask = retryOfTaskId !== null && retryOfTaskId !== undefined
  const autoRetryConfigured = !isRetryTask && (maxRetries ?? 0) > 0
  const retryAttempt = (retryCount ?? 0) + 1
  const canAutoRetry =
    autoRetryConfigured && (retryCount ?? 0) < (maxRetries ?? 0)
  const retriesExhausted =
    autoRetryConfigured && (retryCount ?? 0) >= (maxRetries ?? 0)
  const autoRetryDisabled =
    !isRetryTask && (maxRetries ?? 0) === 0
  return {
    isRetryTask,
    autoRetryConfigured,
    retryAttempt,
    maxRetryAttempts: maxRetries ?? 0,
    canAutoRetry,
    retriesExhausted,
    autoRetryDisabled,
  }
}

export type ChatMessage =
  | { kind: 'user'; entry: HistoryEntry }
  | { kind: 'assistant'; entry: HistoryEntry }
  | { kind: 'tool-result'; entry: HistoryEntry }

/**
 * Flatten a task's history into the chat-stream shape, deduplicating the
 * final response if the last assistant entry echoes the same content.
 */
export function buildChatMessages(
  history: HistoryEntry[] | null | undefined,
  finalResponse: string | null | undefined,
): ChatMessage[] {
  if (!history) return []
  const result: ChatMessage[] = []
  for (const entry of history) {
    if (entry.role === 'user') {
      result.push({ kind: 'user', entry })
    } else if (entry.role === 'assistant' && (entry.content || entry.reasoning)) {
      result.push({ kind: 'assistant', entry })
    } else if (entry.role === 'tool') {
      result.push({ kind: 'tool-result', entry })
    }
  }
  const last = result[result.length - 1]
  if (
    last?.kind === 'assistant' &&
    finalResponse !== null &&
    finalResponse !== undefined &&
    last.entry.content?.trim() === finalResponse.trim()
  ) {
    result.pop()
  }
  return result
}

/**
 * Reasoning from the last assistant message (before deduplication) — shown
 * even when content is hidden, so the user keeps the trace context.
 */
export function findFinalReasoning(
  history: HistoryEntry[] | null | undefined,
  finalResponse: string | null | undefined,
): string | null {
  if (!history?.length || !finalResponse) return null
  const last = history[history.length - 1]
  if (
    last?.role === 'assistant' &&
    last.reasoning &&
    last.content?.trim() === finalResponse.trim()
  ) {
    return last.reasoning
  }
  return null
}

/** Human-readable label for a failing task's error code. */
export function formatErrorCode(code: string | null | undefined): string {
  return code?.replace('_', ' ').toLowerCase() ?? ''
}

/** Per-tool in-flight state helpers shared with ToolApprovalBar. */
export function makeInFlightMaps(): {
  perToolApproving: Record<number, boolean>
  perToolRejecting: Record<number, boolean>
} {
  return { perToolApproving: {}, perToolRejecting: {} }
}

/** Map a pending list + provider-call-id to a ToolCall id (for in-flight flags). */
export function findToolCallId(
  pending: Array<{ id: number; provider_call_id: string }> | null | undefined,
  providerCallId: string,
): number | undefined {
  return pending?.find((t) => t.provider_call_id === providerCallId)?.id
}
