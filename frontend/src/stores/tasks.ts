import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Task, TaskDetail, TaskStatus } from '@/types/task'

const TERMINAL_STATUSES: TaskStatus[] = ['COMPLETED', 'FAILED']

export const useTaskStore = defineStore('tasks', () => {
  const tasks = ref<Task[]>([])
  const activeTask = ref<TaskDetail | null>(null)

  // Polling handles
  let listPollTimer: ReturnType<typeof setTimeout> | null = null
  let listPollGeneration = 0
  let detailPollTimer: ReturnType<typeof setTimeout> | null = null
  let lastSequence = 0

  // ── Task list ──────────────────────────────────────────────────────────────

  async function fetchTasks(): Promise<void> {
    const result = await api.get<{ tasks: Task[] }>('/tasks')
    tasks.value = result.tasks
  }

  async function createTaskForAgent(agentId: number, prompt: string): Promise<Task> {
    const result = await api.post<{ task: Task }>('/tasks', { agent_id: agentId, prompt })
    return result.task
  }

  // ── Task detail ───────────────────────────────────────────────────────────

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
      // First load — replace entirely
      activeTask.value = incoming
      lastSequence = Math.max(...incoming.history.map((h) => h.sequence), 0)
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

  async function rejectTask(taskId: number, reason: string): Promise<void> {
    await api.post(`/tasks/${taskId}/reject`, { reason })
    await fetchTaskDetail(taskId)
  }

  // ── Polling ───────────────────────────────────────────────────────────────

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

  function clearActiveTask(): void {
    stopDetailPolling()
    activeTask.value = null
    lastSequence = 0
  }

  // ── Derived ───────────────────────────────────────────────────────────────

  const pendingToolCalls = computed(() => {
    const calls = activeTask.value?.tool_calls
    return Array.isArray(calls) ? calls.filter((tc) => tc.status === 'PENDING') : []
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
    approveTask,
    rejectTask,
    startListPolling,
    stopListPolling,
    startDetailPolling,
    stopDetailPolling,
    clearActiveTask,
  }
})
