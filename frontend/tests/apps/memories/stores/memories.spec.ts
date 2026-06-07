/**
 * memories store — covers CRUD + reorder actions for global and agent-scoped memories.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const {
  getGlobalMemoriesMock,
  createGlobalMemoryMock,
  updateGlobalMemoryMock,
  deleteGlobalMemoryMock,
  reorderGlobalMemoriesMock,
  getAgentMemoriesMock,
  createAgentMemoryMock,
  updateAgentMemoryMock,
  deleteAgentMemoryMock,
  reorderAgentMemoriesMock,
} = vi.hoisted(() => ({
  getGlobalMemoriesMock: vi.fn(),
  createGlobalMemoryMock: vi.fn(),
  updateGlobalMemoryMock: vi.fn(),
  deleteGlobalMemoryMock: vi.fn(),
  reorderGlobalMemoriesMock: vi.fn(),
  getAgentMemoriesMock: vi.fn(),
  createAgentMemoryMock: vi.fn(),
  updateAgentMemoryMock: vi.fn(),
  deleteAgentMemoryMock: vi.fn(),
  reorderAgentMemoriesMock: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

vi.mock('@/apps/memories/api/memories', () => ({
  getGlobalMemories: getGlobalMemoriesMock,
  createGlobalMemory: createGlobalMemoryMock,
  updateGlobalMemory: updateGlobalMemoryMock,
  deleteGlobalMemory: deleteGlobalMemoryMock,
  reorderGlobalMemories: reorderGlobalMemoriesMock,
  getAgentMemories: getAgentMemoriesMock,
  createAgentMemory: createAgentMemoryMock,
  updateAgentMemory: updateAgentMemoryMock,
  deleteAgentMemory: deleteAgentMemoryMock,
  reorderAgentMemories: reorderAgentMemoriesMock,
}))

import { ApiError } from '@/api/client'
import { useMemoriesStore } from '@/apps/memories/stores/memories'

const sampleMem = (over: Partial<{ id: number; name: string; content: string; order: number; scope: string; agent_id: number | null }> = {}) => ({
  id: 1, name: 'M', content: 'C', order: 0, scope: 'global', agent_id: null, metadata: null, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z', ...over,
})

beforeEach(() => {
  vi.resetAllMocks()
  setActivePinia(createPinia())
})

describe('memories store', () => {
  describe('initial state', () => {
    it('exposes empty lists and null state initially', () => {
      const store = useMemoriesStore()
      expect(store.globalMemories).toEqual([])
      expect(store.agentMemories).toEqual([])
      expect(store.loadingGlobal).toBe(false)
      expect(store.loadingAgent).toBe(false)
      expect(store.saving).toBe(false)
      expect(store.error).toBeNull()
    })
  })

  describe('global memories', () => {
    it('loadGlobalMemories sets loading then globalMemories', async () => {
      getGlobalMemoriesMock.mockResolvedValueOnce([sampleMem({ id: 1, name: 'G1' })])
      const store = useMemoriesStore()
      await store.loadGlobalMemories()
      expect(store.globalMemories).toHaveLength(1)
      expect(store.globalMemories[0].name).toBe('G1')
      expect(store.loadingGlobal).toBe(false)
    })

    it('loadGlobalMemories sets error on ApiError', async () => {
      getGlobalMemoriesMock.mockRejectedValueOnce(new ApiError('boom'))
      const store = useMemoriesStore()
      await store.loadGlobalMemories()
      expect(store.error).toBe('boom')
    })

    it('loadGlobalMemories sets generic error on non-ApiError', async () => {
      getGlobalMemoriesMock.mockRejectedValueOnce(new Error('nope'))
      const store = useMemoriesStore()
      await store.loadGlobalMemories()
      expect(store.error).toBe('Failed to load memories.')
    })

    it('createGlobalMemory appends to the list and returns the new memory', async () => {
      const newMem = sampleMem({ id: 5, name: 'new' })
      createGlobalMemoryMock.mockResolvedValueOnce(newMem)
      const store = useMemoriesStore()
      const result = await store.createGlobalMemory({ name: 'new', content: 'c' })
      expect(result).toEqual(newMem)
      expect(store.globalMemories).toHaveLength(1)
    })

    it('createGlobalMemory throws and sets error on failure', async () => {
      createGlobalMemoryMock.mockRejectedValueOnce(new ApiError('dup'))
      const store = useMemoriesStore()
      await expect(store.createGlobalMemory({ name: 'n', content: 'c' })).rejects.toThrow(ApiError)
      expect(store.error).toBe('dup')
    })

    it('updateGlobalMemory replaces the memory at the matching index', async () => {
      updateGlobalMemoryMock.mockResolvedValueOnce(sampleMem({ id: 1, name: 'updated' }))
      const store = useMemoriesStore()
      store.globalMemories.push(sampleMem({ id: 1, name: 'orig' }))
      await store.updateGlobalMemory(1, { name: 'updated' })
      expect(store.globalMemories[0].name).toBe('updated')
    })

    it('updateGlobalMemory throws and sets error on non-ApiError', async () => {
      updateGlobalMemoryMock.mockRejectedValueOnce(new Error('nope'))
      const store = useMemoriesStore()
      await expect(store.updateGlobalMemory(1, { name: 'x' })).rejects.toThrow()
      expect(store.error).toBe('Failed to update memory.')
    })

    it('deleteGlobalMemory removes from the list', async () => {
      deleteGlobalMemoryMock.mockResolvedValueOnce(undefined)
      const store = useMemoriesStore()
      store.globalMemories.push(sampleMem({ id: 1 }), sampleMem({ id: 2 }))
      await store.deleteGlobalMemory(1)
      expect(store.globalMemories).toHaveLength(1)
      expect(store.globalMemories[0].id).toBe(2)
    })

    it('deleteGlobalMemory throws and sets error on non-ApiError', async () => {
      deleteGlobalMemoryMock.mockRejectedValueOnce(new Error('nope'))
      const store = useMemoriesStore()
      await expect(store.deleteGlobalMemory(1)).rejects.toThrow()
      expect(store.error).toBe('Failed to delete memory.')
    })

    it('reorderGlobalMemories reorders the list in place', async () => {
      reorderGlobalMemoriesMock.mockResolvedValueOnce(undefined)
      const store = useMemoriesStore()
      store.globalMemories.push(sampleMem({ id: 1, order: 0 }), sampleMem({ id: 2, order: 1 }), sampleMem({ id: 3, order: 2 }))
      await store.reorderGlobalMemories([3, 1, 2])
      expect(store.globalMemories.map((m) => m.id)).toEqual([3, 1, 2])
    })

    it('reorderGlobalMemories throws on non-ApiError', async () => {
      reorderGlobalMemoriesMock.mockRejectedValueOnce(new Error('nope'))
      const store = useMemoriesStore()
      await expect(store.reorderGlobalMemories([1])).rejects.toThrow()
      expect(store.error).toBe('Failed to reorder memories.')
    })
  })

  describe('agent memories', () => {
    it('loadAgentMemories sets agentMemories', async () => {
      getAgentMemoriesMock.mockResolvedValueOnce([sampleMem({ id: 1, scope: 'agent', agent_id: 7 })])
      const store = useMemoriesStore()
      await store.loadAgentMemories(7)
      expect(store.agentMemories).toHaveLength(1)
    })

    it('loadAgentMemories sets error on ApiError', async () => {
      getAgentMemoriesMock.mockRejectedValueOnce(new ApiError('err'))
      const store = useMemoriesStore()
      await store.loadAgentMemories(7)
      expect(store.error).toBe('err')
    })

    it('createAgentMemory appends and returns the new memory', async () => {
      const newMem = sampleMem({ id: 11, scope: 'agent', agent_id: 7 })
      createAgentMemoryMock.mockResolvedValueOnce(newMem)
      const store = useMemoriesStore()
      const result = await store.createAgentMemory(7, { name: 'n', content: 'c' })
      expect(result).toEqual(newMem)
      expect(store.agentMemories).toHaveLength(1)
    })

    it('updateAgentMemory replaces at the matching index', async () => {
      updateAgentMemoryMock.mockResolvedValueOnce(sampleMem({ id: 1, name: 'up' }))
      const store = useMemoriesStore()
      store.agentMemories.push(sampleMem({ id: 1, name: 'orig' }))
      await store.updateAgentMemory(7, 1, { name: 'up' })
      expect(store.agentMemories[0].name).toBe('up')
    })

    it('deleteAgentMemory removes from the list', async () => {
      deleteAgentMemoryMock.mockResolvedValueOnce(undefined)
      const store = useMemoriesStore()
      store.agentMemories.push(sampleMem({ id: 1 }), sampleMem({ id: 2 }))
      await store.deleteAgentMemory(7, 1)
      expect(store.agentMemories).toHaveLength(1)
    })

    it('reorderAgentMemories reorders the list', async () => {
      reorderAgentMemoriesMock.mockResolvedValueOnce(undefined)
      const store = useMemoriesStore()
      store.agentMemories.push(sampleMem({ id: 1 }), sampleMem({ id: 2 }), sampleMem({ id: 3 }))
      await store.reorderAgentMemories(7, [3, 1, 2])
      expect(store.agentMemories.map((m) => m.id)).toEqual([3, 1, 2])
    })

    it('reorderAgentMemories throws on non-ApiError', async () => {
      reorderAgentMemoriesMock.mockRejectedValueOnce(new Error('nope'))
      const store = useMemoriesStore()
      await expect(store.reorderAgentMemories(7, [1])).rejects.toThrow()
      expect(store.error).toBe('Failed to reorder memories.')
    })
  })
})
