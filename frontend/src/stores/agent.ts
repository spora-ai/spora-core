import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { Agent, AgentTool, LLMConfigSettings } from '@/types/agent'
import type { Task } from '@/types/task'

export const useAgentStore = defineStore('agent', () => {
  const agents = ref<Agent[]>([])
  const currentAgent = ref<Agent | null>(null)
  const currentAgentTasks = ref<Task[]>([])

  // ── List / CRUD ─────────────────────────────────────────────────────────────

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

  // ── Tasks ───────────────────────────────────────────────────────────────────

  async function fetchAgentTasks(agentId: number): Promise<void> {
    const result = await api.get<{ tasks: Task[] }>(`/tasks?agent_id=${agentId}`)
    currentAgentTasks.value = result.tasks
  }

  async function deleteTask(taskId: number): Promise<void> {
    await api.delete(`/tasks/${taskId}`)
    currentAgentTasks.value = currentAgentTasks.value.filter(t => t.id !== taskId)
  }

  // ── Tools ───────────────────────────────────────────────────────────────────

  async function enableTool(agentId: number, toolName: string): Promise<AgentTool> {
    const result = await api.post<{ tool: AgentTool }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`)
    return result.tool
  }

  async function disableTool(agentId: number, toolName: string): Promise<void> {
    await api.delete(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}/enable`)
  }

  async function patchTool(
    agentId: number,
    toolName: string,
    data: { auto_approve?: boolean | null },
  ): Promise<AgentTool> {
    const result = await api.patch<{ tool: AgentTool }>(`/agents/${agentId}/tools/${encodeURIComponent(toolName)}`, data)
    return result.tool
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

  // ── LLM Config (setup detection) ────────────────────────────────────────────

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

  function clearCurrentAgent(): void {
    currentAgent.value = null
    currentAgentTasks.value = []
  }

  return {
    agents,
    currentAgent,
    currentAgentTasks,
    fetchAgents,
    fetchAgent,
    createAgent,
    updateAgent,
    deleteAgent,
    fetchAgentTasks,
    deleteTask,
    enableTool,
    disableTool,
    patchTool,
    getOperationOverride,
    getAllOperationOverrides,
    patchOperationOverride,
    getLLMConfig,
    putLLMConfig,
    clearCurrentAgent,
  }
})
