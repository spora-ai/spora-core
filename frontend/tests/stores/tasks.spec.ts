import { setActivePinia, createPinia } from 'pinia'
import { useTaskStore } from '@/stores/tasks'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import { api } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

const mockTask = {
  id: 1,
  agent_id: 1,
  status: 'RUNNING',
  user_prompt: 'Do something',
  final_response: null,
  step_count: 1,
  max_steps: 10,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:01Z',
}

const mockTaskDetail = {
  ...mockTask,
  tool_calls: [],
  history: [],
}

describe('useTaskStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchTasks', () => {
    it('fetches and sets tasks list', async () => {
      const tasks = [mockTask]
      mockApi.get.mockResolvedValueOnce({ tasks })

      const store = useTaskStore()
      await store.fetchTasks()

      expect(store.tasks).toEqual(tasks)
    })
  })

  describe('createTaskForAgent', () => {
    it('posts with agent_id and prompt', async () => {
      mockApi.post.mockResolvedValueOnce({ task: mockTask })

      const store = useTaskStore()
      const result = await store.createTaskForAgent(1, 'Do something')

      expect(mockApi.post).toHaveBeenCalledWith('/tasks', {
        agent_id: 1,
        prompt: 'Do something',
      })
      expect(result).toEqual(mockTask)
    })
  })

  describe('fetchTaskDetail', () => {
    it('replaces activeTask on first load', async () => {
      mockApi.get.mockResolvedValueOnce({ task: mockTaskDetail })

      const store = useTaskStore()
      await store.fetchTaskDetail(1)

      expect(store.activeTask).toEqual(mockTaskDetail)
    })

    it('merges incremental history entries (server filters by since_sequence)', async () => {
      const first: typeof mockTaskDetail = {
        ...mockTaskDetail,
        history: [{ sequence: 0, role: 'user' as const, content: 'Hi', tool_call_id: null, tool_name: null }],
      }
      // Second response: server returns only entries where sequence > 0 (the new one)
      const second: typeof mockTaskDetail = {
        ...mockTaskDetail,
        history: [
          { sequence: 1, role: 'assistant' as const, content: 'Hello', tool_call_id: null, tool_name: null },
        ],
      }

      mockApi.get
        .mockResolvedValueOnce({ task: first })
        .mockResolvedValueOnce({ task: second })

      const store = useTaskStore()
      await store.fetchTaskDetail(1)
      await store.fetchTaskDetail(1, 0)

      // Server filters by since_sequence so only new entries are appended
      expect(store.activeTask!.history.length).toBe(2)
      expect(store.activeTask!.history[0].sequence).toBe(0)
      expect(store.activeTask!.history[1].sequence).toBe(1)
    })
  })

  describe('approveTask', () => {
    it('posts approvals and refreshes detail', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)
      mockApi.get.mockResolvedValueOnce({ task: { ...mockTaskDetail, status: 'RUNNING' } })

      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'PENDING_APPROVAL' }
      await store.approveTask(1, [{ provider_call_id: '1', arguments: {} }])

      expect(mockApi.post).toHaveBeenCalledWith('/tasks/1/approve', {
        approvals: [{ provider_call_id: '1', arguments: {} }],
      })
    })
  })

  describe('rejectTask', () => {
    it('posts rejection reason and refreshes detail', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)
      mockApi.get.mockResolvedValueOnce({ task: { ...mockTaskDetail, status: 'RUNNING' } })

      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'PENDING_APPROVAL' }
      await store.rejectTask(1, 'No thanks')

      expect(mockApi.post).toHaveBeenCalledWith('/tasks/1/reject', {
        reason: 'No thanks',
      })
    })
  })

  describe('pendingToolCalls', () => {
    it('returns only PENDING tool calls', () => {
      const store = useTaskStore()
      store.activeTask = {
        ...mockTaskDetail,
        tool_calls: [
          { id: 1, tool_name: 'WebSearch', tool_type: 'search', status: 'PENDING_APPROVAL', proposed_arguments: {}, approved_arguments: null, human_description: null, result_content: null, executed_at: null },
          { id: 2, tool_name: 'Calculator', tool_type: 'calc', status: 'EXECUTED', proposed_arguments: {}, approved_arguments: {}, human_description: null, result_content: '42', executed_at: null },
        ],
      }

      expect(store.pendingToolCalls.length).toBe(1)
      expect(store.pendingToolCalls[0].id).toBe(1)
    })
  })

  describe('isTerminal', () => {
    it('returns true for COMPLETED', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'COMPLETED' }
      expect(store.isTerminal).toBe(true)
    })

    it('returns true for FAILED', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'FAILED' }
      expect(store.isTerminal).toBe(true)
    })

    it('returns false for RUNNING', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'RUNNING' }
      expect(store.isTerminal).toBe(false)
    })

    it('returns false when no activeTask', () => {
      const store = useTaskStore()
      expect(store.isTerminal).toBe(false)
    })
  })

  describe('clearActiveTask', () => {
    it('resets activeTask and lastSequence', () => {
      const store = useTaskStore()
      store.activeTask = mockTaskDetail
      store.clearActiveTask()
      expect(store.activeTask).toBe(null)
    })
  })

  describe('tasksByAgent', () => {
    it('groups tasks by agent_id', () => {
      const store = useTaskStore()
      store.tasks = [
        { ...mockTask, id: 1, agent_id: 1 },
        { ...mockTask, id: 2, agent_id: 2 },
        { ...mockTask, id: 3, agent_id: 1 },
      ]

      const grouped = store.tasksByAgent
      expect(grouped.get(1)?.length).toBe(2)
      expect(grouped.get(2)?.length).toBe(1)
    })
  })

  describe('retryTask', () => {
    it('calls POST /tasks/${taskId}/retry', async () => {
      mockApi.post.mockResolvedValueOnce({ task: { ...mockTask, id: 2 } })

      const store = useTaskStore()
      await store.retryTask(1)

      expect(mockApi.post).toHaveBeenCalledWith('/tasks/1/retry')
    })

    it('returns the new task from the API response', async () => {
      const newTask = { ...mockTask, id: 2, status: 'RUNNING' }
      mockApi.post.mockResolvedValueOnce({ task: newTask })

      const store = useTaskStore()
      const result = await store.retryTask(1)

      expect(result).toEqual(newTask)
    })
  })

  describe('applyTaskUpdate', () => {
    it('merges error_code and error_message from SSE data', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, status: 'RUNNING', error_code: null, error_message: null }

      store.applyTaskUpdate(1, {
        status: 'FAILED',
        error_code: 'RATE_LIMIT',
        error_message: 'API rate limit exceeded.',
      })

      expect(store.activeTask!.status).toBe('FAILED')
      expect(store.activeTask!.error_code).toBe('RATE_LIMIT')
      expect(store.activeTask!.error_message).toBe('API rate limit exceeded.')
    })

    it('does nothing when activeTask is null', () => {
      const store = useTaskStore()
      // Should not throw
      store.applyTaskUpdate(999, { error_code: 'SERVER_ERROR' })
    })

    it('does nothing when taskId does not match activeTask', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail }
      store.applyTaskUpdate(999, { error_code: 'SERVER_ERROR' })
      expect(store.activeTask!.error_code).toBeUndefined()
    })

    it('merges new history entries filtering by sequence', () => {
      const store = useTaskStore()
      store.activeTask = {
        ...mockTaskDetail,
        history: [{ sequence: 0, role: 'user', content: 'Hello', reasoning: null, tool_call_id: null, tool_name: null }],
      }

      // SSE sends a new entry with sequence 1
      store.applyTaskUpdate(1, {
        history: [
          { sequence: 1, role: 'assistant', content: 'Hi there', reasoning: null, tool_call_id: null, tool_name: null },
        ],
      })

      expect(store.activeTask!.history).toHaveLength(2)
      expect(store.activeTask!.history[1].sequence).toBe(1)
    })

    it('does not duplicate history entries on duplicate sequence', () => {
      const store = useTaskStore()
      store.activeTask = {
        ...mockTaskDetail,
        history: [{ sequence: 0, role: 'user', content: 'Hello', reasoning: null, tool_call_id: null, tool_name: null }],
      }

      // Same sequence delivered twice via SSE
      store.applyTaskUpdate(1, {
        history: [{ sequence: 1, role: 'assistant', content: 'First', reasoning: null, tool_call_id: null, tool_name: null }],
      })
      store.applyTaskUpdate(1, {
        history: [{ sequence: 1, role: 'assistant', content: 'Duplicate', reasoning: null, tool_call_id: null, tool_name: null }],
      })

      expect(store.activeTask!.history).toHaveLength(2)
    })

    it('replaces tool_calls entirely from SSE data', () => {
      const store = useTaskStore()
      store.activeTask = {
        ...mockTaskDetail,
        tool_calls: [
          { id: 1, tool_name: 'WebSearch', tool_type: 'search', operation: null, operation_description: null, status: 'PENDING_APPROVAL', proposed_arguments: {}, approved_arguments: null, human_description: null, result_content: null, executed_at: null },
        ],
      }

      const newCalls = [
        { id: 1, tool_name: 'WebSearch', tool_type: 'search', operation: null, operation_description: null, status: 'EXECUTED', proposed_arguments: {}, approved_arguments: {}, human_description: null, result_content: 'Result', executed_at: '2026-01-01T00:00:02Z' },
      ]

      store.applyTaskUpdate(1, { tool_calls: newCalls })

      expect(store.activeTask!.tool_calls).toEqual(newCalls)
    })

    it('updates retry fields from SSE data', () => {
      const store = useTaskStore()
      store.activeTask = { ...mockTaskDetail, retry_of_task_id: null, retry_count: undefined, retry_after: null }

      store.applyTaskUpdate(1, {
        retry_of_task_id: 5,
        retry_count: 2,
        retry_after: '2026-01-01T00:05:00Z',
      })

      expect(store.activeTask!.retry_of_task_id).toBe(5)
      expect(store.activeTask!.retry_count).toBe(2)
      expect(store.activeTask!.retry_after).toBe('2026-01-01T00:05:00Z')
    })

    it('handles lightweight SSE data without tool_calls or history keys (taskListResource shape)', () => {
      // Data from taskListResource / publishIntermediateState has no tool_calls/history
      const store = useTaskStore()
      store.activeTask = {
        ...mockTaskDetail,
        status: 'RUNNING',
        step_count: 1,
        tool_calls: [{ id: 1, tool_name: 'WebSearch', tool_type: 'search', operation: null, operation_description: null, status: 'PENDING_APPROVAL', proposed_arguments: {}, approved_arguments: null, human_description: null, result_content: null, executed_at: null }],
        history: [{ sequence: 0, role: 'user', content: 'Hello', reasoning: null, tool_call_id: null, tool_name: null }],
      }

      // Lightweight SSE update (no tool_calls/history keys)
      store.applyTaskUpdate(1, {
        id: 1,
        status: 'COMPLETED',
        step_count: 3,
        final_response: 'Done',
      })

      // Scalar fields updated
      expect(store.activeTask!.status).toBe('COMPLETED')
      expect(store.activeTask!.step_count).toBe(3)
      expect(store.activeTask!.final_response).toBe('Done')
      // tool_calls and history preserved (not overwritten since keys are absent)
      expect(store.activeTask!.tool_calls).toHaveLength(1)
      expect(store.activeTask!.history).toHaveLength(1)
    })
  })

  describe('lastTaskByAgent', () => {
    it('returns most recent task per agent', () => {
      const store = useTaskStore()
      store.tasks = [
        { ...mockTask, id: 1, agent_id: 1, updated_at: '2026-01-01T00:00:00Z' },
        { ...mockTask, id: 2, agent_id: 1, updated_at: '2026-01-02T00:00:00Z' },
        { ...mockTask, id: 3, agent_id: 2, updated_at: '2026-01-01T00:00:00Z' },
      ]

      const last = store.lastTaskByAgent
      expect(last.get(1)?.id).toBe(2) // newer
      expect(last.get(2)?.id).toBe(3)
    })
  })
})
