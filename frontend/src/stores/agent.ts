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
    if (currentAgent.value?.id === id) currentAgent.value = null
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
    getLLMConfig,
    putLLMConfig,
    clearCurrentAgent,
  }
})
