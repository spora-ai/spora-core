import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAgentStore } from '@/stores/agent'
import type { Task, TaskDetail, TaskStatus, HistoryEntry, TaskErrorCode } from '@/types/task'

/**
 * Manages tasks, active task detail view, polling for updates,
 * SSE-driven real-time updates, and task operations (approve, reject, retry, continue).
 */
const TERMINAL_STATUSES: TaskStatus[] = ['COMPLETED', 'FAILED', 'CANCELLED']

export const useTaskStore = defineStore('tasks', () => {
  const tasks = ref<Task[]>([])
  const activeTask = ref<TaskDetail | null>(null)

  // Polling handles
  let listPollTimer: ReturnType<typeof setTimeout> | null = null
  let listPollGeneration = 0
  let detailPollTimer: ReturnType<typeof setTimeout> | null = null
  let lastSequence = 0
  // Timestamp of the last SSE update processed by applyTaskUpdate (monotonic clock in ms)
  let lastSseUpdateAt = 0
  // Dashboard polling handles
  let dashboardPollTimer: ReturnType<typeof setTimeout> | null = null
  let dashboardPollGen = 0
  let lastDashboardPollAt: string | null = null

  async function fetchTasks(): Promise<void> {
    const result = await api.get<{ tasks: Task[] }>('/tasks')
    tasks.value = result.tasks
  }

  async function createTaskForAgent(agentId: number, prompt: string, parentTaskId?: number): Promise<Task> {
    const payload: Record<string, unknown> = { agent_id: agentId, prompt }
    if (parentTaskId !== undefined) {
      payload.parent_task_id = parentTaskId
    }
    const result = await api.post<{ task: Task }>('/tasks', payload)
    return result.task
  }

  async function fetchTaskDetail(taskId: number, sinceSequence?: number): Promise<boolean> {
    const query = sinceSequence !== undefined ? `?since_sequence=${sinceSequence}` : ''
    let result: { task: TaskDetail }
    try {
      result = await api.get<{ task: TaskDetail }>(`/tasks/${taskId}${query}`)
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        activeTask.value = null
        return false
      }
      throw e
    }
    const incoming = result.task

    if (activeTask.value === null || activeTask.value.id !== taskId) {
      // First load — replace entirely, then apply any pending SSE update for this task
      activeTask.value = incoming
      lastSequence = Math.max(...incoming.history.map((h) => h.sequence), 0)
      // Apply pending SSE update if we have one for this task (handles race where SSE
      // event arrived before fetchTaskDetail completed)
      if (pendingSseUpdate !== null && pendingSseUpdate.taskId === taskId) {
        applyTaskUpdate(taskId, pendingSseUpdate.data)
        pendingSseUpdate = null
      }
    } else {
      // Incremental update: merge new history entries and refresh scalar fields
      activeTask.value.status = incoming.status
      activeTask.value.final_response = incoming.final_response
      activeTask.value.step_count = incoming.step_count
      activeTask.value.updated_at = incoming.updated_at
      // Append new history entries, filtering by sequence to guard against
      // duplicate delivery from concurrent in-flight requests.
      if (incoming.history.length > 0) {
        const newEntries = incoming.history.filter((h) => h.sequence > lastSequence)
        if (newEntries.length > 0) {
          activeTask.value.history.push(...newEntries)
          lastSequence = newEntries[newEntries.length - 1].sequence
        }
      }
      // Refresh tool_calls on every poll (status may change on resume)
      activeTask.value.tool_calls = incoming.tool_calls
    }
    return true
  }

  async function approveTask(taskId: number, approvals: { provider_call_id: string; arguments: Record<string, unknown> }[]): Promise<void> {
    await api.post(`/tasks/${taskId}/approve`, { approvals })
    await fetchTaskDetail(taskId)
  }

  async function retryTask(taskId: number): Promise<Task> {
    const result = await api.post<{ task: Task }>(`/tasks/${taskId}/retry`)
    return result.task
  }

  async function continueTask(taskId: number, prompt: string, additionalSteps?: number): Promise<Task> {
    const body: Record<string, unknown> = { prompt }
    if (additionalSteps !== undefined) {
      body.additional_steps = additionalSteps
    }
    const result = await api.post<{ task: Task }>(`/tasks/${taskId}/continue`, body)
    return result.task
  }

  async function rejectTask(taskId: number, reason: string): Promise<void> {
    await api.post(`/tasks/${taskId}/reject`, { reason })
    await fetchTaskDetail(taskId)
  }

  async function cancelRetryChain(taskId: number): Promise<void> {
    await api.delete(`/tasks/${taskId}/retry-chain`)
  }

  async function fetchTask(taskId: number): Promise<Task> {
    const result = await api.get<{ task: Task }>(`/tasks/${taskId}`)
    if (activeTask.value?.id === taskId) {
      // Refresh scalar fields from the fetched task
      activeTask.value.status = result.task.status
      activeTask.value.final_response = result.task.final_response
      activeTask.value.step_count = result.task.step_count
      activeTask.value.updated_at = result.task.updated_at
      activeTask.value.error_code = result.task.error_code
      activeTask.value.error_message = result.task.error_message
    }
    return result.task
  }

  function startListPolling(): void {
    const gen = ++listPollGeneration
    if (listPollTimer !== null) {
      clearTimeout(listPollTimer)
      listPollTimer = null
    }
    const tick = async () => {
      if (listPollGeneration !== gen) return
      try {
        await fetchTasks()
      } finally {
        if (listPollGeneration === gen) {
          const hasActive = tasks.value.some((t) => !TERMINAL_STATUSES.includes(t.status))
          listPollTimer = setTimeout(tick, hasActive ? 3000 : 10000)
        }
      }
    }
    listPollTimer = setTimeout(tick, 3000)
  }

  function stopListPolling(): void {
    listPollGeneration++
    if (listPollTimer !== null) {
      clearTimeout(listPollTimer)
      listPollTimer = null
    }
  }

  function startDetailPolling(taskId: number): void {
    // Skip polling if SSE provided data within the last 3 seconds — SSE will drive updates
    if (Date.now() - lastSseUpdateAt < 3000) return
    stopDetailPolling()
    const tick = async () => {
      if (activeTask.value === null || activeTask.value.id !== taskId) return
      if (TERMINAL_STATUSES.includes(activeTask.value.status)) return
      const ok = await fetchTaskDetail(taskId, lastSequence)
      if (!ok) return // task was deleted
      if (activeTask.value && !TERMINAL_STATUSES.includes(activeTask.value.status)) {
        detailPollTimer = setTimeout(tick, 2000)
      }
    }
    detailPollTimer = setTimeout(tick, 2000)
  }

  function stopDetailPolling(): void {
    if (detailPollTimer !== null) {
      clearTimeout(detailPollTimer)
      detailPollTimer = null
    }
  }

  /**
   * Merge a real-time task update from SSE into the tasks[] array (Dashboard).
   * Mirrors applyTaskUpdate but operates on tasks.value instead of activeTask.
   */
  function applySseEventToTasks(data: Record<string, unknown>): void {
    const taskId = (data.id ?? data.task_id) as number | undefined
    if (taskId === undefined) return
    const idx = tasks.value.findIndex((t) => t.id === taskId)
    if (idx !== -1) {
      Object.assign(tasks.value[idx], {
        status: (data.status as Task['status']) ?? tasks.value[idx].status,
        step_count: (data.step_count as number) ?? tasks.value[idx].step_count,
        final_response: (data.final_response as string | null) ?? tasks.value[idx].final_response,
        updated_at: (data.updated_at as string) ?? tasks.value[idx].updated_at,
      })
    } else {
      if (data.status !== undefined) {
        tasks.value.unshift({
          id: taskId,
          agent_id: (data as { agent_id?: number }).agent_id ?? 0,
          status: data.status as Task['status'],
          user_prompt: (data as { user_prompt?: string }).user_prompt ?? '',
          final_response: (data.final_response as string | null) ?? null,
          step_count: (data.step_count as number) ?? 0,
          max_steps: null,
          created_at: (data.created_at as string) ?? new Date().toISOString(),
          updated_at: (data.updated_at as string) ?? new Date().toISOString(),
        })
      }
    }
  }

  function startDashboardPolling(): void {
    const gen = ++dashboardPollGen
    if (dashboardPollTimer !== null) {
      clearTimeout(dashboardPollTimer)
      dashboardPollTimer = null
    }
    const tick = async () => {
      if (dashboardPollGen !== gen) return
      try {
        const query = lastDashboardPollAt ? `?since=${encodeURIComponent(lastDashboardPollAt)}` : ''
        const result = await api.get<{ tasks: Task[]; server_time: string }>(`/tasks${query}`)
        if (!result || !Array.isArray(result.tasks)) return
        // Merge: update existing tasks, prepend new ones
        for (const t of result.tasks) {
          const idx = tasks.value.findIndex((x) => x.id === t.id)
          if (idx !== -1) {
            tasks.value[idx] = t
          } else {
            tasks.value.unshift(t)
          }
        }
        if (result.server_time) {
          lastDashboardPollAt = result.server_time
        }
        // Also sync with agentStore.currentAgentTasks so AgentPage picks up new tasks
        const agentStore = useAgentStore()
        for (const t of result.tasks) {
          agentStore.applySseTaskEvent(t as unknown as Record<string, unknown>)
        }
      } catch {
        // Network or API error — keep polling, don't crash
      } finally {
        if (dashboardPollGen === gen) {
          dashboardPollTimer = setTimeout(tick, 30_000) // every 30s
        }
      }
    }
    dashboardPollTimer = setTimeout(tick, 30_000)
  }

  function stopDashboardPolling(): void {
    dashboardPollGen++
    if (dashboardPollTimer !== null) {
      clearTimeout(dashboardPollTimer)
      dashboardPollTimer = null
    }
  }

  function clearActiveTask(): void {
    stopDetailPolling()
    activeTask.value = null
    lastSequence = 0
  }

  /**
   * Pending SSE update stored when activeTask is not yet loaded.
   * Used to apply the first SSE event when fetchTaskDetail hasn't completed yet.
   */
  let pendingSseUpdate: { taskId: number; data: Record<string, unknown> } | null = null

  /**
   * Merge a real-time task update from SSE into activeTask.
   * Used by useRealtime when Mercure pushes a task/* event.
   *
   * If activeTask is not yet loaded (null or different taskId), the update is stored
   * as pending and applied once the correct task is loaded via fetchTaskDetail.
   */
  function applyTaskUpdate(taskId: number, data: Record<string, unknown>): void {
    // Ignore events for a task that has already reached a terminal state
    if (activeTask.value?.id === taskId && TERMINAL_STATUSES.includes(activeTask.value.status)) return

    if (activeTask.value === null) {
      // Store as pending — will be applied by fetchTaskDetail once activeTask is set
      pendingSseUpdate = { taskId, data }
      return
    }
    if (activeTask.value.id !== taskId) return
    // Apply pending update if this is the right task
    if (pendingSseUpdate !== null && pendingSseUpdate.taskId === taskId) {
      pendingSseUpdate = null
    }
    // SSE has provided fresh data — stop detail polling so SSE drives updates
    stopDetailPolling()
    lastSseUpdateAt = Date.now()
    // Apply scalar fields
    if (data.status !== undefined) activeTask.value.status = data.status as TaskStatus
    if (data.final_response !== undefined) activeTask.value.final_response = data.final_response as string | null
    if (data.step_count !== undefined) activeTask.value.step_count = data.step_count as number
    if (data.updated_at !== undefined) activeTask.value.updated_at = data.updated_at as string
    // Merge new history entries
    if (Array.isArray(data.history)) {
      const newEntries = (data.history as unknown as HistoryEntry[]).filter(h => h.sequence > lastSequence)
      if (newEntries.length > 0) {
        activeTask.value.history.push(...newEntries)
        lastSequence = newEntries[newEntries.length - 1].sequence
      }
    }
    // Refresh tool_calls
    if (Array.isArray(data.tool_calls)) {
      activeTask.value.tool_calls = data.tool_calls as TaskDetail['tool_calls']
    }
    // Merge error fields
    if (data.error_code !== undefined) activeTask.value.error_code = data.error_code as TaskErrorCode | null
    if (data.error_message !== undefined) activeTask.value.error_message = data.error_message as string | null
    // Merge retry fields
    if (data.retry_of_task_id !== undefined) activeTask.value.retry_of_task_id = data.retry_of_task_id as number | null
    if (data.retry_count !== undefined) activeTask.value.retry_count = data.retry_count as number | undefined
    if (data.retry_after !== undefined) activeTask.value.retry_after = data.retry_after as string | null
  }

  const pendingToolCalls = computed(() => {
    const calls = activeTask.value?.tool_calls
    return Array.isArray(calls) ? calls.filter((tc) => tc.status === 'PENDING_APPROVAL') : []
  })

  const isTerminal = computed(() =>
    activeTask.value !== null && TERMINAL_STATUSES.includes(activeTask.value.status),
  )

  /** All tasks grouped by agent_id, sorted by updated_at desc */
  const tasksByAgent = computed(() => {
    const map = new Map<number, Task[]>()
    for (const task of tasks.value) {
      if (!map.has(task.agent_id)) {
        map.set(task.agent_id, [])
      }
      map.get(task.agent_id)!.push(task)
    }
    return map
  })

  /** Most recent task per agent (by updated_at) */
  const lastTaskByAgent = computed(() => {
    const map = new Map<number, Task>()
    for (const task of tasks.value) {
      const existing = map.get(task.agent_id)
      if (!existing || new Date(task.updated_at) > new Date(existing.updated_at)) {
        map.set(task.agent_id, task)
      }
    }
    return map
  })

  return {
    tasks,
    activeTask,
    pendingToolCalls,
    isTerminal,
    tasksByAgent,
    lastTaskByAgent,
    fetchTasks,
    createTaskForAgent,
    fetchTaskDetail,
    fetchTask,
    approveTask,
    rejectTask,
    retryTask,
    continueTask,
    cancelRetryChain,
    startListPolling,
    stopListPolling,
    startDetailPolling,
    stopDetailPolling,
    clearActiveTask,
    applyTaskUpdate,
    applySseEventToTasks,
    startDashboardPolling,
    stopDashboardPolling,
  }
})
