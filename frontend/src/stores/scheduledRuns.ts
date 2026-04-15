import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { ScheduledRunResource } from '@/types/scheduledRun'

export const useScheduledRunsStore = defineStore('scheduledRuns', () => {
  const runs = ref<ScheduledRunResource[]>([])

  async function fetchRuns(agentId: number): Promise<ScheduledRunResource[]> {
    const result = await api.get<{ data: { scheduled_runs: ScheduledRunResource[] } }>(
      `/agents/${agentId}/scheduled-runs`,
    )
    runs.value = result.data.scheduled_runs
    return result.data.scheduled_runs
  }

  async function createRun(
    agentId: number,
    payload: Record<string, unknown>,
  ): Promise<ScheduledRunResource> {
    const result = await api.post<{ data: { scheduled_run: ScheduledRunResource } }>(
      `/agents/${agentId}/scheduled-runs`,
      payload,
    )
    runs.value.unshift(result.data.scheduled_run)
    return result.data.scheduled_run
  }

  async function updateRun(
    agentId: number,
    runId: number,
    payload: Record<string, unknown>,
  ): Promise<ScheduledRunResource> {
    const result = await api.put<{ data: { scheduled_run: ScheduledRunResource } }>(
      `/agents/${agentId}/scheduled-runs/${runId}`,
      payload,
    )
    const idx = runs.value.findIndex((r) => r.id === runId)
    if (idx !== -1) runs.value[idx] = result.data.scheduled_run
    return result.data.scheduled_run
  }

  async function deleteRun(agentId: number, runId: number): Promise<void> {
    await api.delete(`/agents/${agentId}/scheduled-runs/${runId}`)
    runs.value = runs.value.filter((r) => r.id !== runId)
  }

  async function triggerRun(agentId: number, runId: number): Promise<void> {
    await api.post<{ data: { scheduled_run: ScheduledRunResource } }>(
      `/agents/${agentId}/scheduled-runs/${runId}/trigger`,
    )
  }

  return { runs, fetchRuns, createRun, updateRun, deleteRun, triggerRun }
})
