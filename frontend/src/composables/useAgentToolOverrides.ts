/**
 * useAgentToolOverrides — HTTP helpers for per-agent tool enable/disable and
 * per-operation approval overrides.
 *
 * These are pure functions (no Pinia, no Vue lifecycle) so the agent store
 * can call them as plain functions, and tests can mock `@/api/client` without
 * standing up a Pinia instance. The store remains the single owner of the
 * reactive override map; this file only handles the wire format.
 */
import { api } from '@/api/client'
import type { AgentTool, LLMConfigSettings } from '@/types/agent'

export interface OperationOverride {
  operation: string
  tool_class: string
  enabled: boolean | null
  default_requires_approval: boolean | null
  effective_enabled: boolean
  effective_requires_approval: boolean
}

export interface OperationOverridePatch {
  enabled?: boolean | null
  default_requires_approval?: boolean | null
}

/** Map returned by GET /agents/{id}/tools/operations — keyed by tool_name, then operation. */
export type AllOperationOverrides = Record<
  string,
  Record<string, { enabled: boolean; requiresApproval: boolean }>
>

export async function enableTool(agentId: number, toolName: string): Promise<AgentTool> {
  const result = await api.post<{ tool: AgentTool }>(
    `/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`,
  )
  return result.tool
}

export async function disableTool(agentId: number, toolName: string): Promise<void> {
  await api.delete(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`)
}

export async function getOperationOverride(
  agentId: number,
  toolName: string,
  operation: string,
): Promise<OperationOverride> {
  const result = await api.get<{
    enabled: boolean | null
    default_requires_approval: boolean | null
    effective_enabled: boolean
    effective_requires_approval: boolean
  }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/operations/${encodeURIComponent(operation)}`)
  return result as unknown as OperationOverride
}

/**
 * GET /api/v1/agents/{id}/tools/operations — all operation overrides for all enabled tools.
 * Returns a flat array; caller transforms it into the nested Record used by the UI.
 */
export async function getAllOperationOverrides(
  agentId: number,
): Promise<AllOperationOverrides> {
  const result = await api.get<{
    operations: Array<{
      tool_class: string
      tool_name: string
      operation: string
      effective_enabled: boolean
      effective_requires_approval: boolean
    }>
  }>(`/agents/${agentId}/tools/operations`)

  // Re-key by tool_name (authoritative server value). patchOperationOverride uses
  // tool_name as the URL identifier, so the UI map must use tool_name keys too.
  const byName: AllOperationOverrides = {}
  for (const op of result.operations) {
    if (!byName[op.tool_name]) {
      byName[op.tool_name] = {}
    }
    byName[op.tool_name][op.operation] = {
      enabled: op.effective_enabled,
      requiresApproval: op.effective_requires_approval,
    }
  }
  return byName
}

export async function patchOperationOverride(
  agentId: number,
  toolName: string,
  operation: string,
  data: OperationOverridePatch,
): Promise<OperationOverride> {
  const result = await api.patch<{
    enabled: boolean | null
    default_requires_approval: boolean | null
    effective_enabled: boolean
    effective_requires_approval: boolean
  }>(
    `/agents/${agentId}/tools/${encodeURIComponent(toolName)}/operations/${encodeURIComponent(operation)}`,
    data,
  )
  return result as unknown as OperationOverride
}

export async function getLLMConfig(agentId: number): Promise<LLMConfigSettings> {
  const result = await api.get<{ settings: LLMConfigSettings }>(
    `/agents/${agentId}/tools/${encodeURIComponent('llm_configuration')}/override`,
  )
  return result.settings
}

export async function putLLMConfig(
  agentId: number,
  settings: LLMConfigSettings,
): Promise<LLMConfigSettings> {
  const result = await api.put<{ settings: LLMConfigSettings }>(
    `/agents/${agentId}/tools/${encodeURIComponent('llm_configuration')}/override`,
    { settings },
  )
  return result.settings
}
