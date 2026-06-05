/**
 * useToolApproval — pure helpers for the tool-approval components.
 *
 * Owns the parse-or-return-null JSON-arg parser (used by both
 * ToolApprovalCard and the parent ToolApprovalBar), the per-tool in-flight
 * map updates, the proposed-args projection, and the bulk-approvals builder
 * that prefers edited values over proposed values.
 */
import type { ToolCall } from '@/types/task'

export type PendingToolCall = Pick<ToolCall, 'id' | 'provider_call_id' | 'proposed_arguments' | 'tool_name'>

/** Parse a JSON string into a plain object, or return null. */
export function tryParseArgsObject(json: string): Record<string, unknown> | null {
  try {
    const parsed = JSON.parse(json) as unknown
    if (typeof parsed === 'object' && parsed !== null) {
      return parsed as Record<string, unknown>
    }
  } catch {
    /* fall through */
  }
  return null
}

/**
 * Project a ToolCall's `proposed_arguments` into a plain object. The
 * backend may send either an object or a JSON string (double-escaped).
 */
export function normalizeProposedArgs(
  proposed: unknown,
): Record<string, unknown> {
  if (!proposed) return {}
  if (typeof proposed === 'object') return proposed as Record<string, unknown>
  if (typeof proposed === 'string') {
    const parsed = tryParseArgsObject(proposed)
    if (parsed !== null) return parsed
  }
  return {}
}

/** Stringify the proposed-args object for the editable JSON textarea. */
export function prettyPrintArgs(args: Record<string, unknown>): string {
  return JSON.stringify(args, null, 2)
}

export interface ApprovalPayload {
  providerCallId: string
  arguments: Record<string, unknown>
}

/**
 * Build the bulk-approve payload from the pending list and the snapshot
 * of edited arguments captured by ToolApprovalBar. Edited values win
 * over the tool's originally proposed arguments.
 */
export function buildBulkApprovals(
  pending: PendingToolCall[],
  editedArgs: Record<string, Record<string, unknown>>,
): ApprovalPayload[] {
  return pending.map((tc) => {
    const edited = editedArgs[tc.provider_call_id]
    if (edited !== undefined) {
      return { providerCallId: tc.provider_call_id, arguments: edited }
    }
    return {
      providerCallId: tc.provider_call_id,
      arguments: normalizeProposedArgs(tc.proposed_arguments),
    }
  })
}

/** Default reason for "reject all" when the user left the field empty. */
export const REJECT_ALL_DEFAULT_REASON = 'No reason provided.'

/** Default reason for a single "reject" when the user left the field empty. */
export const REJECT_ONE_DEFAULT_REASON = 'User rejected'

/** Resolve a per-tool in-flight flag from the per-tool map (default false). */
export function inFlightFlag(
  map: Record<number, boolean> | undefined,
  id: number,
): boolean {
  return map?.[id] ?? false
}

/** Drop stale entries from the edited-args snapshot when a tool call leaves the pending list. */
export function pruneEditedArgs(
  editedArgs: Record<string, Record<string, unknown>>,
  allowedProviderCallIds: Iterable<string>,
): Record<string, Record<string, unknown>> {
  const allowed = new Set(allowedProviderCallIds)
  const next: Record<string, Record<string, unknown>> = {}
  for (const [id, args] of Object.entries(editedArgs)) {
    if (allowed.has(id)) next[id] = args
  }
  return next
}

/** Default per-tool in-flight maps. */
export function emptyInFlightMaps(): {
  perToolApproving: Record<number, boolean>
  perToolRejecting: Record<number, boolean>
} {
  return { perToolApproving: {}, perToolRejecting: {} }
}
