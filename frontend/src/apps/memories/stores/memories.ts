import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError } from '@/api/client'
import type { MemoryResource, CreateMemoryDto, UpdateMemoryDto } from '../types/memory'
import * as api from '../api/memories'

/**
 * Manages global and agent-scoped memories with CRUD and reordering.
 */
export const useMemoriesStore = defineStore('memories', () => {
  // State
  const globalMemories = ref<MemoryResource[]>([])
  const agentMemories = ref<MemoryResource[]>([])
  const loadingGlobal = ref(false)
  const loadingAgent = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  // Global memories

  async function loadGlobalMemories(): Promise<void> {
    loadingGlobal.value = true
    error.value = null
    try {
      globalMemories.value = await api.getGlobalMemories()
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load memories.'
    } finally {
      loadingGlobal.value = false
    }
  }

  async function createGlobalMemory(data: CreateMemoryDto): Promise<MemoryResource> {
    saving.value = true
    error.value = null
    try {
      const memory = await api.createGlobalMemory(data)
      globalMemories.value.push(memory)
      return memory
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to create memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateGlobalMemory(id: number, data: UpdateMemoryDto): Promise<MemoryResource> {
    saving.value = true
    error.value = null
    try {
      const memory = await api.updateGlobalMemory(id, data)
      const idx = globalMemories.value.findIndex((m) => m.id === id)
      if (idx !== -1) globalMemories.value[idx] = memory
      return memory
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to update memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteGlobalMemory(id: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.deleteGlobalMemory(id)
      globalMemories.value = globalMemories.value.filter((m) => m.id !== id)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to delete memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  // Agent memories

  async function loadAgentMemories(agentId: number): Promise<void> {
    loadingAgent.value = true
    error.value = null
    try {
      agentMemories.value = await api.getAgentMemories(agentId)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load agent memories.'
    } finally {
      loadingAgent.value = false
    }
  }

  async function createAgentMemory(agentId: number, data: CreateMemoryDto): Promise<MemoryResource> {
    saving.value = true
    error.value = null
    try {
      const memory = await api.createAgentMemory(agentId, data)
      agentMemories.value.push(memory)
      return memory
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to create agent memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateAgentMemory(agentId: number, memoryId: number, data: UpdateMemoryDto): Promise<MemoryResource> {
    saving.value = true
    error.value = null
    try {
      const memory = await api.updateAgentMemory(agentId, memoryId, data)
      const idx = agentMemories.value.findIndex((m) => m.id === memoryId)
      if (idx !== -1) agentMemories.value[idx] = memory
      return memory
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to update agent memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteAgentMemory(agentId: number, memoryId: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.deleteAgentMemory(agentId, memoryId)
      agentMemories.value = agentMemories.value.filter((m) => m.id !== memoryId)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to delete agent memory.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function reorderGlobalMemories(orderedIds: number[]): Promise<void> {
    error.value = null
    try {
      await api.reorderGlobalMemories(orderedIds)
      const ordered = orderedIds
        .map((id) => globalMemories.value.find((m) => m.id === id))
        .filter((m): m is MemoryResource => m !== undefined)
      globalMemories.value = ordered
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to reorder memories.'
      throw e
    }
  }

  async function reorderAgentMemories(agentId: number, orderedIds: number[]): Promise<void> {
    error.value = null
    try {
      await api.reorderAgentMemories(agentId, orderedIds)
      const ordered = orderedIds
        .map((id) => agentMemories.value.find((m) => m.id === id))
        .filter((m): m is MemoryResource => m !== undefined)
      agentMemories.value = ordered
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to reorder memories.'
      throw e
    }
  }

  return {
    globalMemories,
    agentMemories,
    loadingGlobal,
    loadingAgent,
    saving,
    error,
    loadGlobalMemories,
    createGlobalMemory,
    updateGlobalMemory,
    deleteGlobalMemory,
    reorderGlobalMemories,
    loadAgentMemories,
    createAgentMemory,
    updateAgentMemory,
    deleteAgentMemory,
    reorderAgentMemories,
  }
})
