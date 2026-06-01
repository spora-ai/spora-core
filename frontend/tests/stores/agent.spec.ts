import { setActivePinia, createPinia } from 'pinia'
import { useAgentStore } from '@/stores/agent'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

// Plain object store used by our sessionStorage mock
const sessionStorageStore: Record<string, string> = {}

function createSessionStorageMock() {
  return {
    getItem: (key: string) => sessionStorageStore[key] ?? null,
    setItem: (key: string, value: string) => { sessionStorageStore[key] = value },
    removeItem: (key: string) => { delete sessionStorageStore[key] },
    clear: () => { for (const k in sessionStorageStore) delete sessionStorageStore[k] },
  }
}

const mockSessionStorage = createSessionStorageMock()
Object.defineProperty(global, 'sessionStorage', { value: mockSessionStorage })

function resetSessionStorage(): void {
  for (const k in sessionStorageStore) delete sessionStorageStore[k]
}

const mockAgent = {
  id: 1,
  name: 'Test Agent',
  description: 'A test agent',
  recipe_id: null,
  system_prompt: null,
  llm_provider: 'openai_compatible',
  llm_model: 'gpt-4o',
  llm_base_url: null,
  max_steps: 10,
  is_active: true,
  tools: [],
}

describe('useAgentStore', () => {
  beforeEach(() => {
    // Reset only API mocks individually — vi.resetAllMocks() kills sessionStorageSpy implementation
    mockApi.get.mockReset()
    mockApi.post.mockReset()
    mockApi.patch.mockReset()
    mockApi.put.mockReset()
    mockApi.delete.mockReset()
    resetSessionStorage()
    setActivePinia(createPinia())
  })

  describe('fetchAgents', () => {
    it('fetches and sets agents list', async () => {
      const agents = [mockAgent, { ...mockAgent, id: 2, name: 'Agent 2' }]
      mockApi.get.mockResolvedValueOnce({ agents })

      const store = useAgentStore()
      await store.fetchAgents()

      expect(store.agents).toEqual(agents)
    })
  })

  describe('fetchAgent', () => {
    it('fetches single agent and sets currentAgent', async () => {
      mockApi.get.mockResolvedValueOnce({ agent: mockAgent })

      const store = useAgentStore()
      const result = await store.fetchAgent(1)

      expect(store.currentAgent).toEqual(mockAgent)
      expect(result).toEqual(mockAgent)
    })
  })

  describe('createAgent', () => {
    it('posts to /agents and prepends to agents list', async () => {
      mockApi.post.mockResolvedValueOnce({ agent: mockAgent })

      const store = useAgentStore()
      const result = await store.createAgent({ name: 'Test Agent' })

      expect(mockApi.post).toHaveBeenCalledWith('/agents', { name: 'Test Agent' })
      expect(store.agents[0]).toEqual(mockAgent)
      expect(result).toEqual(mockAgent)
    })
  })

  describe('updateAgent', () => {
    it('patches agent and updates in list and currentAgent', async () => {
      const updated = { ...mockAgent, name: 'Updated Name' }
      mockApi.patch.mockResolvedValueOnce({ agent: updated })

      const store = useAgentStore()
      store.agents = [mockAgent]
      store.currentAgent = mockAgent

      const result = await store.updateAgent(1, { name: 'Updated Name' })

      expect(store.agents[0].name).toBe('Updated Name')
      expect(store.currentAgent.name).toBe('Updated Name')
      expect(result).toEqual(updated)
    })
  })

  describe('deleteAgent', () => {
    it('removes agent from list and clears currentAgent if matched', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useAgentStore()
      store.agents = [mockAgent, { ...mockAgent, id: 2 }]
      store.currentAgent = mockAgent

      await store.deleteAgent(1)

      expect(store.agents.length).toBe(1)
      expect(store.agents[0].id).toBe(2)
      expect(store.currentAgent).toBe(null)
    })
  })

  describe('fetchAgentTasks', () => {
    it('fetches tasks for agent and sets currentAgentTasks', async () => {
      const tasks = [
        { id: 1, agent_id: 1, status: 'COMPLETED', user_prompt: 'Do thing', final_response: 'Done', step_count: 2, max_steps: 10, created_at: '', updated_at: '' },
      ]
      mockApi.get.mockResolvedValueOnce({ tasks })

      const store = useAgentStore()
      await store.fetchAgentTasks(1)

      expect(store.currentAgentTasks).toEqual(tasks)
    })
  })

  describe('applySseTaskEvent', () => {
    it('updates existing task scalar fields when id matches', () => {
      const store = useAgentStore()
      store.currentAgentTasks = [
        { id: 5, agent_id: 1, status: 'RUNNING', user_prompt: 'Hello', final_response: null, step_count: 1, max_steps: 10, created_at: '2024-01-01T00:00:00Z', updated_at: '2024-01-01T00:00:00Z' },
      ]

      store.applySseTaskEvent({ id: 5, status: 'COMPLETED', step_count: 3, final_response: 'Done', updated_at: '2024-01-01T00:01:00Z' })

      expect(store.currentAgentTasks[0].status).toBe('COMPLETED')
      expect(store.currentAgentTasks[0].step_count).toBe(3)
      expect(store.currentAgentTasks[0].final_response).toBe('Done')
      expect(store.currentAgentTasks[0].updated_at).toBe('2024-01-01T00:01:00Z')
    })

    it('prepends new task when id has no match', () => {
      const store = useAgentStore()
      store.currentAgentTasks = [
        { id: 5, agent_id: 1, status: 'COMPLETED', user_prompt: 'Existing', final_response: null, step_count: 2, max_steps: 10, created_at: '2024-01-01T00:00:00Z', updated_at: '2024-01-01T00:00:00Z' },
      ]

      store.applySseTaskEvent({ id: 99, agent_id: 1, status: 'PENDING_APPROVAL', user_prompt: 'New task', step_count: 0 })

      expect(store.currentAgentTasks.length).toBe(2)
      expect(store.currentAgentTasks[0].id).toBe(99)
      expect(store.currentAgentTasks[0].status).toBe('PENDING_APPROVAL')
      expect(store.currentAgentTasks[0].user_prompt).toBe('New task')
    })

    it('does not prepend when status is undefined (no-op for non-task events)', () => {
      const store = useAgentStore()
      store.currentAgentTasks = [
        { id: 5, agent_id: 1, status: 'RUNNING', user_prompt: 'Hello', final_response: null, step_count: 1, max_steps: 10, created_at: '2024-01-01T00:00:00Z', updated_at: '2024-01-01T00:00:00Z' },
      ]

      store.applySseTaskEvent({ id: 99 }) // no status field

      expect(store.currentAgentTasks.length).toBe(1)
      expect(store.currentAgentTasks[0].id).toBe(5)
    })

    it('handles task_id as the identifier (explicit publish path)', () => {
      const store = useAgentStore()
      store.currentAgentTasks = [
        { id: 7, agent_id: 1, status: 'RUNNING', user_prompt: 'Hello', final_response: null, step_count: 1, max_steps: 10, created_at: '2024-01-01T00:00:00Z', updated_at: '2024-01-01T00:00:00Z' },
      ]

      store.applySseTaskEvent({ task_id: 7, status: 'FAILED', error_code: 'TOOL_ERROR' })

      expect(store.currentAgentTasks[0].status).toBe('FAILED')
    })

    it('does not crash when currentAgentTasks is empty', () => {
      const store = useAgentStore()
      store.currentAgentTasks = []

      expect(() => store.applySseTaskEvent({ id: 1, status: 'COMPLETED' })).not.toThrow()
      expect(store.currentAgentTasks.length).toBe(1)
      expect(store.currentAgentTasks[0].id).toBe(1)
    })
  })

  describe('enableTool / disableTool', () => {
    it('enableTool calls POST and returns tool', async () => {
      const tool = { tool_class: 'TestTool', tool_name: 'TestTool', auto_approve: null }
      mockApi.post.mockResolvedValueOnce({ tool })

      const store = useAgentStore()
      const result = await store.enableTool(1, 'TestTool')

      expect(mockApi.post).toHaveBeenCalledWith('/agents/1/tools/TestTool/enable')
      expect(result).toEqual(tool)
    })

    it('disableTool calls DELETE', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useAgentStore()
      await store.disableTool(1, 'TestTool')

      expect(mockApi.delete).toHaveBeenCalledWith('/agents/1/tools/TestTool/enable')
    })
  })

  describe('patchTool', () => {
    it('calls PATCH with auto_approve data', async () => {
      const tool = { tool_class: 'TestTool', tool_name: 'TestTool', auto_approve: true }
      mockApi.patch.mockResolvedValueOnce({ tool })

      const store = useAgentStore()
      const result = await store.patchTool(1, 'TestTool', { auto_approve: true })

      expect(mockApi.patch).toHaveBeenCalledWith('/agents/1/tools/TestTool', { auto_approve: true })
      expect(result).toEqual(tool)
    })
  })

  describe('getAllOperationOverrides', () => {
    it('fetches and maps operation overrides by tool name', async () => {
      mockApi.get.mockResolvedValueOnce({
        operations: [
          { tool_class: 'App\\Tools\\FileTool', tool_name: 'file_tool', operation: 'read', effective_enabled: true, effective_requires_approval: false },
          { tool_class: 'App\\Tools\\FileTool', tool_name: 'file_tool', operation: 'write', effective_enabled: false, effective_requires_approval: true },
        ],
      })

      const store = useAgentStore()
      const result = await store.getAllOperationOverrides(1)

      expect(mockApi.get).toHaveBeenCalledWith('/agents/1/tools/operations')
      expect(result).toEqual({
        file_tool: {
          read: { enabled: true, requiresApproval: false },
          write: { enabled: false, requiresApproval: true },
        },
      })
    })

    it('handles empty operations list', async () => {
      mockApi.get.mockResolvedValueOnce({ operations: [] })
      const store = useAgentStore()
      const result = await store.getAllOperationOverrides(1)
      expect(result).toEqual({})
    })
  })

  describe('getLLMConfig', () => {
    it('fetches LLM config override for agent', async () => {
      const config = { 'core.openai.api_key': 'sk-test' }
      mockApi.get.mockResolvedValueOnce({ settings: config })

      const store = useAgentStore()
      const result = await store.getLLMConfig(1)

      expect(mockApi.get).toHaveBeenCalledWith(
        '/agents/1/tools/llm_configuration/override',
      )
      expect(result).toEqual(config)
    })
  })

  describe('putLLMConfig', () => {
    it('puts LLM config and returns updated settings', async () => {
      const config = { 'core.openai.api_key': 'sk-updated' }
      mockApi.put.mockResolvedValueOnce({ settings: config })

      const store = useAgentStore()
      const result = await store.putLLMConfig(1, config)

      expect(mockApi.put).toHaveBeenCalledWith(
        '/agents/1/tools/llm_configuration/override',
        { settings: config },
      )
      expect(result).toEqual(config)
    })
  })

  describe('clearCurrentAgent', () => {
    it('resets currentAgent and currentAgentTasks', () => {
      const store = useAgentStore()
      store.currentAgent = mockAgent
      store.currentAgentTasks = [{ id: 1, agent_id: 1, status: 'COMPLETED', user_prompt: 'x', final_response: null, step_count: 0, max_steps: 10, created_at: '', updated_at: '' }]

      store.clearCurrentAgent()

      expect(store.currentAgent).toBe(null)
      expect(store.currentAgentTasks).toEqual([])
    })
  })

  /**
   * Simulates the tool status sync that AgentSettingsPage.vue performs in onMounted.
   * When agent.tools contains a tool but toolStatusMap says is_enabled=false,
   * enabledToolNames must NOT include it.
   *
   * Bug: agent.tools is the list of associated tools, not the enabled state.
   * toolStatusMap from GET /agents/{id}/tools/status is the authoritative source.
   */
  describe('tool status sync (AgentSettingsPage onMounted simulation)', () => {
    it('does not mark a tool as enabled when toolStatusMap.is_enabled is false', () => {
      // Simulate agent.tools — the agent is associated with AgentMemoryTool
      const agentTools = [
        { tool_name: 'memory', tool_class: 'AgentMemoryTool' },
        { tool_name: 'calculator', tool_class: 'CalculatorTool' },
      ]

      // Simulate toolStatusMap from GET /agents/{id}/tools/status
      const toolStatusMap: Record<string, { is_enabled: boolean }> = {
        memory: { is_enabled: false },
        calculator: { is_enabled: true },
      }

      // This is the sync logic from AgentSettingsPage.vue onMounted:
      const enabledToolNames = new Set<string>()
      for (const tool of agentTools) {
        const status = toolStatusMap[tool.tool_name]
        if (status) {
          if (status.is_enabled) {
            enabledToolNames.add(tool.tool_name)
          } else {
            enabledToolNames.delete(tool.tool_name)
          }
        }
      }

      // AgentMemoryTool should NOT be enabled (is_enabled=false)
      expect(enabledToolNames.has('memory')).toBe(false)
      // Calculator should be enabled (is_enabled=true)
      expect(enabledToolNames.has('calculator')).toBe(true)
    })

    it('adds a tool to enabledToolNames when is_enabled is true', () => {
      const agentTools = [
        { tool_name: 'global_memory', tool_class: 'GlobalMemoryTool' },
      ]
      const toolStatusMap: Record<string, { is_enabled: boolean }> = {
        global_memory: { is_enabled: true },
      }

      const enabledToolNames = new Set<string>()
      for (const tool of agentTools) {
        const status = toolStatusMap[tool.tool_name]
        if (status?.is_enabled) {
          enabledToolNames.add(tool.tool_name)
        }
      }

      expect(enabledToolNames.has('global_memory')).toBe(true)
    })
  })

  describe('composerDrafts', () => {
    it('getComposerDraft creates a new draft lazily for unknown agent', () => {
      const store = useAgentStore()

      const draft1 = store.getComposerDraft(1)
      expect(draft1).toEqual({ promptText: '' })
      expect(store.composerDrafts[1]).toEqual({ promptText: '' })
    })

    it('getComposerDraft returns same instance on multiple calls', () => {
      const store = useAgentStore()

      const draft1 = store.getComposerDraft(1)
      const draft2 = store.getComposerDraft(1)
      expect(draft1).toBe(draft2)
    })

    it('getComposerDraft returns independent drafts for different agents', () => {
      const store = useAgentStore()

      const draft1 = store.getComposerDraft(1)
      const draft2 = store.getComposerDraft(2)
      draft1.promptText = 'Hello'
      draft2.promptText = 'World'

      expect(store.composerDrafts[1].promptText).toBe('Hello')
      expect(store.composerDrafts[2].promptText).toBe('World')
    })

    it('clearComposerDraft resets promptText to empty string', () => {
      const store = useAgentStore()

      const draft = store.getComposerDraft(1)
      draft.promptText = 'Some draft text'
      expect(draft.promptText).toBe('Some draft text')

      store.clearComposerDraft(1)
      expect(draft.promptText).toBe('')
    })

    it('clearComposerDraft does not throw for unknown agent', () => {
      const store = useAgentStore()
      expect(() => store.clearComposerDraft(999)).not.toThrow()
    })

    it('drafts are persisted to sessionStorage', async () => {
      setActivePinia(createPinia())
      const store = useAgentStore()

      store.getComposerDraft(1).promptText = 'Test prompt'

      // Wait a tick for the watch to flush
      await new Promise(resolve => setTimeout(resolve, 0))

      expect(sessionStorageStore['spora:composer-drafts']).toBe(
        JSON.stringify({ 1: { promptText: 'Test prompt' } }),
      )
    })

    it('drafts are loaded from sessionStorage on init', () => {
      sessionStorageStore['spora:composer-drafts'] = JSON.stringify({ 5: { promptText: 'Loaded draft' } })

      setActivePinia(createPinia())
      const store = useAgentStore()

      expect(store.composerDrafts[5]).toEqual({ promptText: 'Loaded draft' })
    })
  })
})
