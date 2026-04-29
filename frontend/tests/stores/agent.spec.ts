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
    vi.resetAllMocks()
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

  describe('getAllOperationOverrides', () => {
    it('calls GET /agents/{id}/tools/operations and returns nested map', async () => {
      const operationsResponse = {
        operations: [
          {
            tool_class: 'TestTool',
            operation: 'search',
            effective_enabled: true,
            effective_requires_approval: false,
          },
          {
            tool_class: 'TestTool',
            operation: 'scrape',
            effective_enabled: false,
            effective_requires_approval: true,
          },
        ],
      }
      mockApi.get.mockResolvedValueOnce(operationsResponse)

      const store = useAgentStore()
      const result = await store.getAllOperationOverrides(1)

      expect(mockApi.get).toHaveBeenCalledWith('/agents/1/tools/operations')
      expect(result).toEqual({
        test_tool: {
          search: { enabled: true, requiresApproval: false },
          scrape: { enabled: false, requiresApproval: true },
        },
      })
    })

    it('returns empty object when no operations exist', async () => {
      mockApi.get.mockResolvedValueOnce({ operations: [] })

      const store = useAgentStore()
      const result = await store.getAllOperationOverrides(42)

      expect(result).toEqual({})
    })
  })
})
