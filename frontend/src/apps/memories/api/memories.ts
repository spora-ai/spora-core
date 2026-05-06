import { api } from '@/api/client'
import type { MemoryResource, CreateMemoryDto, UpdateMemoryDto } from '../types/memory'

export async function getGlobalMemories(): Promise<MemoryResource[]> {
  const result = await api.get<{ memories: MemoryResource[] }>('/memories')
  return result.memories
}

export async function getGlobalMemory(id: number): Promise<MemoryResource> {
  const result = await api.get<{ memory: MemoryResource }>(`/memories/${id}`)
  return result.memory
}

export async function createGlobalMemory(data: CreateMemoryDto): Promise<MemoryResource> {
  const result = await api.post<{ memory: MemoryResource }>('/memories', data)
  return result.memory
}

export async function updateGlobalMemory(id: number, data: UpdateMemoryDto): Promise<MemoryResource> {
  const result = await api.put<{ memory: MemoryResource }>(`/memories/${id}`, data)
  return result.memory
}

export async function deleteGlobalMemory(id: number): Promise<void> {
  await api.delete(`/memories/${id}`)
}

export async function getAgentMemories(agentId: number): Promise<MemoryResource[]> {
  const result = await api.get<{ memories: MemoryResource[] }>(`/agents/${agentId}/memories`)
  return result.memories
}

export async function getAgentMemory(agentId: number, memoryId: number): Promise<MemoryResource> {
  const result = await api.get<{ memory: MemoryResource }>(`/agents/${agentId}/memories/${memoryId}`)
  return result.memory
}

export async function createAgentMemory(agentId: number, data: CreateMemoryDto): Promise<MemoryResource> {
  const result = await api.post<{ memory: MemoryResource }>(`/agents/${agentId}/memories`, data)
  return result.memory
}

export async function updateAgentMemory(agentId: number, memoryId: number, data: UpdateMemoryDto): Promise<MemoryResource> {
  const result = await api.put<{ memory: MemoryResource }>(`/agents/${agentId}/memories/${memoryId}`, data)
  return result.memory
}

export async function deleteAgentMemory(agentId: number, memoryId: number): Promise<void> {
  await api.delete(`/agents/${agentId}/memories/${memoryId}`)
}
