import { defineStore } from 'pinia'
import { ref, reactive, watch } from 'vue'
import { api } from '@/api/client'
import type { Agent, AgentTool, LLMConfigSettings } from '@/types/agent'
import type { Task } from '@/types/task'

const COMPOSER_DRAFTS_KEY = 'spora:composer-drafts'

function loadComposerDrafts(): Record<number, { promptText: string }> {
  try {
    const stored = sessionStorage.getItem(COMPOSER_DRAFTS_KEY)
    return stored ? JSON.parse(stored) : {}
  } catch {
    return {}
  }
}

function saveComposerDrafts(drafts: Record<number, { promptText: string }>): void {
  try {
    sessionStorage.setItem(COMPOSER_DRAFTS_KEY, JSON.stringify(drafts))
  } catch {
    // sessionStorage may be unavailable (e.g., private browsing)
  }
}

async function enableTool(agentId: number, toolName: string): Promise<AgentTool> {
  const result = await api.post<{ tool: AgentTool }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`)
  return result.tool
}

async function disableTool(agentId: number, toolName: string): Promise<void> {
  await api.delete(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`)
}

async function getOperationOverride(
  agentId: number,
  toolName: string,
  operation: string,
): Promise<{
  operation: string
  tool_class: string
  enabled: boolean | null
  default_requires_approval: boolean | null
  effective_enabled: boolean
  effective_requires_approval: boolean
}> {
  const result = await api.get<{
    enabled: boolean | null
    default_requires_approval: boolean | null
    effective_enabled: boolean
    effective_requires_approval: boolean
  }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/operations/${encodeURIComponent(operation)}`)
  return result as any
}

/**
 * GET /api/v1/agents/{id}/tools/operations — all operation overrides for all enabled tools.
 * Returns a flat array; caller transforms it into the nested Record used by the UI.
 */
async function getAllOperationOverrides(
  agentId: number,
): Promise<Record<string, Record<string, { enabled: boolean; requiresApproval: boolean }>>> {
  const result = await api.get<{
    operations: Array<{
      tool_class: string
      tool_name: string
      operation: string
      effective_enabled: boolean
      effective_requires_approval: boolean
    }>
  }>(`/agents/${agentId}/tools/operations`)

  // Re-key by tool_name using the authoritative value from the server.
  // patchOperationOverride uses tool_name as the URL identifier, so the map must use tool_name keys.
  const byName: Record<string, Record<string, { enabled: boolean; requiresApproval: boolean }>> = {}
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

async function patchOperationOverride(
  agentId: number,
  toolName: string,
  operation: string,
  data: { enabled?: boolean | null; default_requires_approval?: boolean | null },
): Promise<{
  enabled: boolean | null
  default_requires_approval: boolean | null
  effective_enabled: boolean
  effective_requires_approval: boolean
}> {
  const result = await api.patch<{
    enabled: boolean | null
    default_requires_approval: boolean | null
    effective_enabled: boolean
    effective_requires_approval: boolean
  }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/operations/${encodeURIComponent(operation)}`, data)
  return result as any
}

async function getLLMConfig(agentId: number): Promise<LLMConfigSettings> {
  const result = await api.get<{ settings: LLMConfigSettings }>(
    `/agents/${agentId}/tools/${encodeURIComponent('llm_configuration')}/override`,
  )
  return result.settings
}

async function putLLMConfig(agentId: number, settings: LLMConfigSettings): Promise<LLMConfigSettings> {
  const result = await api.put<{ settings: LLMConfigSettings }>(
    `/agents/${agentId}/tools/${encodeURIComponent('llm_configuration')}/override`,
    { settings },
  )
  return result.settings
}

export const useAgentStore = defineStore('agent', () => {
  const agents = ref<Agent[]>([])
  const currentAgent = ref<Agent | null>(null)
  const currentAgentTasks = ref<Task[]>([])
  const tasksCurrentPage = ref(1)
  const tasksHasMore = ref(false)
  const tasksTotal = ref(0)
  const composerDrafts = reactive<Record<number, { promptText: string }>>(loadComposerDrafts())

  // Auto-persist drafts to sessionStorage
  watch(composerDrafts, (drafts) => {
    saveComposerDrafts(drafts)
  }, { deep: true })

  function getComposerDraft(agentId: number): { promptText: string } {
    if (!composerDrafts[agentId]) {
      composerDrafts[agentId] = { promptText: '' }
    }
    return composerDrafts[agentId]
  }

  function clearComposerDraft(agentId: number): void {
    if (composerDrafts[agentId]) {
      composerDrafts[agentId].promptText = ''
    }
  }


  async function fetchAgents(): Promise<void> {
    const result = await api.get<{ agents: Agent[] }>('/agents')
    agents.value = result.agents
  }

  async function fetchAgent(id: number): Promise<Agent> {
    const result = await api.get<{ agent: Agent }>(`/agents/${id}`)
    currentAgent.value = result.agent
    return result.agent
  }

  async function createAgent(data: {
    name: string
    description?: string
    system_prompt?: string
    llm_driver_config_id?: number | null
    max_steps?: number
  }): Promise<Agent> {
    const result = await api.post<{ agent: Agent }>('/agents', data)
    agents.value.unshift(result.agent)
    return result.agent
  }

  async function updateAgent(
    id: number,
    data: Partial<{
      name: string
      description: string | null
      system_prompt: string | null
      llm_driver_config_id: number | null
      max_steps: number
      allow_continuation: boolean
      retry_after_minutes: number
      max_retries: number
    }>,
  ): Promise<Agent> {
    const result = await api.patch<{ agent: Agent }>(`/agents/${id}`, data)
    const idx = agents.value.findIndex((a) => a.id === id)
    if (idx !== -1) agents.value[idx] = result.agent
    if (currentAgent.value?.id === id) currentAgent.value = result.agent
    return result.agent
  }

  async function deleteAgent(id: number): Promise<void> {
    await api.delete(`/agents/${id}`)
    agents.value = agents.value.filter((a) => a.id !== id)
    if (currentAgent.value?.id === id) {
      currentAgent.value = null
      currentAgentTasks.value = []
    }
  }


  async function fetchAgentTasks(agentId: number, options?: { page?: number }): Promise<void> {
    const page = options?.page ?? 1
    const params = new URLSearchParams({ agent_id: String(agentId), page: String(page) })
    const result = await api.get<{ tasks: Task[]; meta?: { current_page: number; last_page: number; per_page: number; total: number } }>(`/tasks?${params}`)
    if (page === 1) {
      currentAgentTasks.value = result.tasks
    } else {
      currentAgentTasks.value.push(...result.tasks)
    }
    if (result.meta) {
      tasksCurrentPage.value = result.meta.current_page
      tasksHasMore.value = result.meta.current_page < result.meta.last_page
      tasksTotal.value = result.meta.total
    }
  }

  const tasksLoading = ref(false)

  async function loadMoreTasks(): Promise<void> {
    if (!tasksHasMore.value) return
    const agentId = currentAgent.value?.id
    if (!agentId) return
    tasksLoading.value = true
    try {
      await fetchAgentTasks(agentId, { page: tasksCurrentPage.value + 1 })
    } finally {
      tasksLoading.value = false
    }
  }

  async function deleteTask(taskId: number): Promise<void> {
    await api.delete(`/tasks/${taskId}`)
    currentAgentTasks.value = currentAgentTasks.value.filter(t => t.id !== taskId)
  }

  /**
   * Called by useRealtime when a SSE task event arrives.
   * Updates an existing task in currentAgentTasks or prepends a new one.
   */
  /**
   * Called by useRealtime when a SSE task event arrives.
   * Also called during SSE fallback polling to keep currentAgentTasks in sync.
   * Only applies updates for tasks belonging to the currentAgent.
   */
  function applySseTaskEvent(data: Record<string, unknown>): void {
    const taskId = (data.id ?? data.task_id) as number | undefined
    if (taskId === undefined) return

    const taskAgentId = (data as { agent_id?: number }).agent_id

    // If we know which agent this task belongs to and it's not the current one, skip it
    if (currentAgent.value !== null && taskAgentId !== undefined && taskAgentId !== currentAgent.value.id) {
      return
    }

    const idx = currentAgentTasks.value.findIndex(t => t.id === taskId)
    if (idx !== -1) {
      Object.assign(currentAgentTasks.value[idx], {
        status: (data.status as Task['status']) ?? currentAgentTasks.value[idx].status,
        step_count: (data.step_count as number) ?? currentAgentTasks.value[idx].step_count,
        final_response: (data.final_response as string | null) ?? currentAgentTasks.value[idx].final_response,
        updated_at: (data.updated_at as string) ?? currentAgentTasks.value[idx].updated_at,
      })
    } else if (data.status !== undefined) {
      currentAgentTasks.value.unshift({
        id: taskId,
        agent_id: taskAgentId ?? currentAgent.value?.id ?? 0,
        status: data.status as Task['status'],
        user_prompt: (data as { user_prompt?: string }).user_prompt ?? '',
        final_response: (data.final_response as string | null) ?? null,
        step_count: (data.step_count as number) ?? 0,
        max_steps: null,
        updated_at: (data.updated_at as string) ?? new Date().toISOString(),
        created_at: (data.created_at as string) ?? new Date().toISOString(),
      })
    }
  }


  function clearCurrentAgent(): void {
    currentAgent.value = null
    currentAgentTasks.value = []
    tasksCurrentPage.value = 1
    tasksHasMore.value = false
    tasksTotal.value = 0
  }

  return {
    agents,
    currentAgent,
    currentAgentTasks,
    tasksCurrentPage,
    tasksHasMore,
    tasksTotal,
    tasksLoading,
    composerDrafts,
    fetchAgents,
    fetchAgent,
    createAgent,
    updateAgent,
    deleteAgent,
    fetchAgentTasks,
    loadMoreTasks,
    deleteTask,
    applySseTaskEvent,
    enableTool,
    disableTool,
    getOperationOverride,
    getAllOperationOverrides,
    patchOperationOverride,
    getLLMConfig,
    putLLMConfig,
    clearCurrentAgent,
    getComposerDraft,
    clearComposerDraft,
  }
})
