import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/api/client'
import type { Task, TaskDetail, TaskStatus } from '@/types/task'

const TERMINAL_STATUSES: TaskStatus[] = ['COMPLETED', 'FAILED']

export const useTaskStore = defineStore('tasks', () => {
  const tasks = ref<Task[]>([])
  const activeTask = ref<TaskDetail | null>(null)

  // Polling handles
  let listPollTimer: ReturnType<typeof setTimeout> | null = null
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

  async function fetchTaskDetail(taskId: number, sinceSequence?: number): Promise<void> {
    const query = sinceSequence !== undefined ? `?since_sequence=${sinceSequence}` : ''
    const result = await api.get<{ task: TaskDetail }>(`/tasks/${taskId}${query}`)
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
      // Append new history (since_sequence guarantees no duplicates)
      if (incoming.history.length > 0) {
        activeTask.value.history.push(...incoming.history)
        lastSequence = incoming.history[incoming.history.length - 1].sequence
      }
      // Refresh tool_calls on every poll (status may change on resume)
      activeTask.value.tool_calls = incoming.tool_calls
    }
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
    stopListPolling()
    const tick = async () => {
      try {
        await fetchTasks()
      } finally {
        const hasActive = tasks.value.some((t) => !TERMINAL_STATUSES.includes(t.status))
        listPollTimer = setTimeout(tick, hasActive ? 3000 : 10000)
      }
    }
    listPollTimer = setTimeout(tick, 3000)
  }

  function stopListPolling(): void {
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
      try {
        await fetchTaskDetail(taskId, lastSequence)
      } finally {
        if (activeTask.value && !TERMINAL_STATUSES.includes(activeTask.value.status)) {
          detailPollTimer = setTimeout(tick, 2000)
        }
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

  const pendingToolCalls = computed(() =>
    activeTask.value?.tool_calls.filter((tc) => tc.status === 'PENDING') ?? [],
  )

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
