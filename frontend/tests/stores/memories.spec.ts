import { setActivePinia, createPinia } from 'pinia'
import { useMemoriesStore } from '@/apps/memories/stores/memories'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(public message: string) { super(message) }
  },
}))

import { api, ApiError } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

function createMockMemory(overrides: Partial<{
  id: number
  name: string
  summary: string | null
  content: string | null
  order: number
  agent_id: number | null
}> = {}): ReturnType<typeof vi.fn> {
  return vi.fn().mockResolvedValue({
    memory: {
      id: 1,
      user_id: 1,
      agent_id: null,
      name: 'Test Memory',
      summary: null,
      content: null,
      order: 1,
      created_at: '2025-01-01T00:00:00Z',
      updated_at: '2025-01-01T00:00:00Z',
      ...overrides,
    },
  })
}

describe('useMemoriesStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockApi.get.mockReset()
    mockApi.post.mockReset()
    mockApi.patch.mockReset()
    mockApi.put.mockReset()
    mockApi.delete.mockReset()
  })

  describe('createAgentMemory', () => {
    it('calls API and appends new memory to agentMemories list', async () => {
      const store = useMemoriesStore()
      const mockMemory = {
        id: 5,
        user_id: 1,
        agent_id: 3,
        name: 'new_memory',
        summary: null,
        content: null,
        order: 1,
        created_at: '2025-01-01T00:00:00Z',
        updated_at: '2025-01-01T00:00:00Z',
      }
      mockApi.post.mockResolvedValue({ memory: mockMemory })

      const result = await store.createAgentMemory(3, { name: 'new_memory' })

      expect(mockApi.post).toHaveBeenCalledWith('/agents/3/memories', { name: 'new_memory' })
      expect(result).toEqual(mockMemory)
      expect(store.agentMemories).toContainEqual(mockMemory)
    })
  })

  describe('reorderAgentMemories', () => {
    it('calls PATCH endpoint with ordered IDs array', async () => {
      const store = useMemoriesStore()
      store.agentMemories = [
        { id: 1, user_id: 1, agent_id: 3, name: 'm1', summary: null, content: null, order: 1, created_at: '', updated_at: '' },
        { id: 2, user_id: 1, agent_id: 3, name: 'm2', summary: null, content: null, order: 2, created_at: '', updated_at: '' },
      ]
      mockApi.patch.mockResolvedValue({ success: true })

      await store.reorderAgentMemories(3, [2, 1])

      expect(mockApi.patch).toHaveBeenCalledWith('/agents/3/memories/reorder', { order: [2, 1] })
      expect(store.agentMemories[0].id).toBe(2)
      expect(store.agentMemories[1].id).toBe(1)
    })

    it('sets error and throws on API failure', async () => {
      const store = useMemoriesStore()
      mockApi.patch.mockRejectedValue(new ApiError('Network error'))

      await expect(store.reorderAgentMemories(3, [1, 2])).rejects.toThrow('Network error')
      expect(store.error).toBe('Network error')
    })
  })

  describe('reorderGlobalMemories', () => {
    it('calls PATCH endpoint with ordered IDs array', async () => {
      const store = useMemoriesStore()
      store.globalMemories = [
        { id: 1, user_id: 1, agent_id: null, name: 'g1', summary: null, content: null, order: 1, created_at: '', updated_at: '' },
        { id: 2, user_id: 1, agent_id: null, name: 'g2', summary: null, content: null, order: 2, created_at: '', updated_at: '' },
      ]
      mockApi.patch.mockResolvedValue({ success: true })

      await store.reorderGlobalMemories([2, 1])

      expect(mockApi.patch).toHaveBeenCalledWith('/memories/reorder', { order: [2, 1] })
      expect(store.globalMemories[0].id).toBe(2)
      expect(store.globalMemories[1].id).toBe(1)
    })
  })
})
