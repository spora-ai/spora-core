/**
 * useTaskChatFollowup — follow-up prompt state + submit handler.
 *
 * Mocks the task store + agent store. Covers the showFollowupBar visibility
 * matrix, the success and error branches of submitFollowup, and the empty-
 * prompt no-op.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) {
      super(message)
      this.name = 'ApiError'
    }
  },
}))

const activeTaskRef = ref<Record<string, unknown> | null>(null)
const taskStoreMock = {
  get activeTask() { return activeTaskRef.value },
  continueTask: vi.fn(),
  fetchTaskDetail: vi.fn(),
  startDetailPolling: vi.fn(),
  isTerminal: false,
}
vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => taskStoreMock,
}))

const currentAgentRef = ref<{ allow_continuation?: boolean } | null>(null)
const agentStoreMock = {
  get currentAgent() { return currentAgentRef.value },
}
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => agentStoreMock,
}))

import { useTaskChatFollowup } from '@/composables/useTaskChatFollowup'

function setActiveTask(overrides: Record<string, unknown> = {}): void {
  activeTaskRef.value = { id: 1, status: 'COMPLETED', ...overrides }
}

function setAgent(allowContinuation: boolean | undefined = true): void {
  currentAgentRef.value = { allow_continuation: allowContinuation }
}

beforeEach(() => {
  activeTaskRef.value = null
  currentAgentRef.value = null
  taskStoreMock.continueTask.mockReset()
  taskStoreMock.continueTask.mockResolvedValue(undefined)
  taskStoreMock.fetchTaskDetail.mockReset()
  taskStoreMock.fetchTaskDetail.mockResolvedValue(undefined)
  taskStoreMock.startDetailPolling.mockReset()
  taskStoreMock.isTerminal = false
})

describe('useTaskChatFollowup', () => {
  describe('showFollowupBar', () => {
    it('is false when there is no active task', () => {
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(false)
    })

    it('is true when task is COMPLETED and agent allows continuation', () => {
      setActiveTask({ status: 'COMPLETED' })
      setAgent(true)
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(true)
    })

    it('is true when task is FAILED and agent allows continuation', () => {
      setActiveTask({ status: 'FAILED' })
      setAgent(true)
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(true)
    })

    it('is false for running tasks', () => {
      setActiveTask({ status: 'RUNNING' })
      setAgent(true)
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(false)
    })

    it('is false when agent disallows continuation', () => {
      setActiveTask({ status: 'COMPLETED' })
      setAgent(false)
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(false)
    })

    it('is false when there is no current agent', () => {
      setActiveTask({ status: 'COMPLETED' })
      const c = useTaskChatFollowup()
      expect(c.showFollowupBar.value).toBe(false)
    })
  })

  describe('submitFollowup', () => {
    it('calls continueTask and starts detail polling for non-terminal tasks', async () => {
      setActiveTask()
      setAgent()
      taskStoreMock.isTerminal = false
      const c = useTaskChatFollowup()
      c.followupPrompt.value = 'follow-up question'
      await c.submitFollowup()
      expect(taskStoreMock.continueTask).toHaveBeenCalledWith(1, 'follow-up question')
      expect(taskStoreMock.fetchTaskDetail).toHaveBeenCalledWith(1)
      expect(taskStoreMock.startDetailPolling).toHaveBeenCalledWith(1)
      expect(c.followupPrompt.value).toBe('')
      expect(c.submittingFollowup.value).toBe(false)
    })

    it('does NOT start polling for terminal tasks', async () => {
      setActiveTask()
      setAgent()
      taskStoreMock.isTerminal = true
      const c = useTaskChatFollowup()
      c.followupPrompt.value = 'done'
      await c.submitFollowup()
      expect(taskStoreMock.continueTask).toHaveBeenCalled()
      expect(taskStoreMock.startDetailPolling).not.toHaveBeenCalled()
    })

    it('captures ApiError message into followupError', async () => {
      setActiveTask()
      setAgent()
      const { ApiError } = await import('@/api/client')
      taskStoreMock.continueTask.mockRejectedValueOnce(new ApiError('limit reached'))
      const c = useTaskChatFollowup()
      c.followupPrompt.value = 'hi'
      await c.submitFollowup()
      expect(c.followupError.value).toBe('limit reached')
      expect(c.followupPrompt.value).toBe('hi') // prompt preserved on error
      expect(c.submittingFollowup.value).toBe(false)
    })

    it('uses a generic fallback for non-ApiError rejections', async () => {
      setActiveTask()
      setAgent()
      taskStoreMock.continueTask.mockRejectedValueOnce(new Error('boom'))
      const c = useTaskChatFollowup()
      c.followupPrompt.value = 'hi'
      await c.submitFollowup()
      expect(c.followupError.value).toBe('Failed to submit follow-up.')
    })

    it('is a no-op for an empty prompt', async () => {
      setActiveTask()
      const c = useTaskChatFollowup()
      c.followupPrompt.value = '   '
      await c.submitFollowup()
      expect(taskStoreMock.continueTask).not.toHaveBeenCalled()
    })

    it('is a no-op when there is no active task', async () => {
      const c = useTaskChatFollowup()
      c.followupPrompt.value = 'hi'
      await c.submitFollowup()
      expect(taskStoreMock.continueTask).not.toHaveBeenCalled()
    })

    it('clears followupError at the start of the call', async () => {
      setActiveTask()
      setAgent()
      const c = useTaskChatFollowup()
      c.followupError.value = 'old'
      c.followupPrompt.value = 'new'
      await c.submitFollowup()
      expect(c.followupError.value).toBeNull()
    })
  })
})
