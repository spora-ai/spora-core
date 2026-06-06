/**
 * useTaskChatApprovals — per-tool in-flight flags + bulk approve/reject.
 *
 * Mocks the task store + toast so the composable can run in isolation.
 * Covers the success and error branches of all four handlers, the per-tool
 * map bookkeeping, and the callback that re-scrolls on the page.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

const toastMock = { error: vi.fn(), success: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

const pendingToolCallsRef = ref<Array<{ id: number; provider_call_id: string; tool_name: string }>>([])
const taskStoreMock = {
  get pendingToolCalls() { return pendingToolCallsRef.value },
  approveTask: vi.fn(),
  rejectTask: vi.fn(),
  startDetailPolling: vi.fn(),
}
vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => taskStoreMock,
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) {
      super(message)
      this.name = 'ApiError'
    }
  },
}))

import { useTaskChatApprovals } from '@/composables/useTaskChatApprovals'

const taskId = ref(1)
const onAfterMutation = vi.fn()

beforeEach(() => {
  pendingToolCallsRef.value = []
  toastMock.error.mockClear()
  toastMock.success.mockClear()
  taskStoreMock.approveTask.mockReset()
  taskStoreMock.approveTask.mockResolvedValue(undefined)
  taskStoreMock.rejectTask.mockReset()
  taskStoreMock.rejectTask.mockResolvedValue(undefined)
  taskStoreMock.startDetailPolling.mockReset()
  onAfterMutation.mockReset()
})

describe('useTaskChatApprovals', () => {
  describe('onApproveAll', () => {
    it('maps payload fields and calls store.approveTask once', async () => {
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onApproveAll({
        approvals: [
          { providerCallId: 'pc-1', arguments: { x: 1 } },
          { providerCallId: 'pc-2', arguments: { y: 2 } },
        ],
      })
      expect(taskStoreMock.approveTask).toHaveBeenCalledWith(1, [
        { provider_call_id: 'pc-1', arguments: { x: 1 } },
        { provider_call_id: 'pc-2', arguments: { y: 2 } },
      ])
      expect(toastMock.success).toHaveBeenCalledWith('All tools approved.')
      expect(taskStoreMock.startDetailPolling).toHaveBeenCalledWith(1)
      expect(onAfterMutation).toHaveBeenCalled()
      expect(c.approvingAll.value).toBe(false)
    })

    it('surfaces the error and stores it in approveError', async () => {
      taskStoreMock.approveTask.mockRejectedValueOnce(new Error('nope'))
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onApproveAll({ approvals: [] })
      expect(toastMock.error).toHaveBeenCalledWith('nope')
      expect(c.approveError.value).toBe('nope')
      expect(c.approvingAll.value).toBe(false)
    })

    it('falls back to a generic message when the rejection is not an Error', async () => {
      taskStoreMock.approveTask.mockRejectedValueOnce('plain string error')
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onApproveAll({ approvals: [] })
      expect(toastMock.error).toHaveBeenCalledWith('Approval failed.')
      expect(c.approveError.value).toBe('Approval failed.')
    })

    it('clears approveError at the start of the call', async () => {
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      c.approveError.value = 'old error'
      await c.onApproveAll({ approvals: [] })
      expect(c.approveError.value).toBeNull()
    })
  })

  describe('onRejectAll', () => {
    it('calls store.rejectTask with the reason and triggers a toast', async () => {
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onRejectAll({ reason: 'too risky' })
      expect(taskStoreMock.rejectTask).toHaveBeenCalledWith(1, 'too risky')
      expect(toastMock.success).toHaveBeenCalledWith('All tools rejected.')
      expect(taskStoreMock.startDetailPolling).toHaveBeenCalledWith(1)
      expect(onAfterMutation).toHaveBeenCalled()
      expect(c.rejecting.value).toBe(false)
    })

    it('surfaces the error and resets state', async () => {
      const { ApiError } = await import('@/api/client')
      taskStoreMock.rejectTask.mockRejectedValueOnce(new ApiError('bad'))
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onRejectAll({ reason: 'x' })
      expect(toastMock.error).toHaveBeenCalledWith('bad')
      expect(c.approveError.value).toBe('bad')
      expect(c.rejecting.value).toBe(false)
    })
  })

  describe('onApproveOne', () => {
    it('looks up the tool by provider_call_id, marks it in-flight, and approves', async () => {
      pendingToolCallsRef.value = [
        { id: 7, provider_call_id: 'pc-1', tool_name: 'web_search' },
      ]
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      const approvePromise = c.onApproveOne({ providerCallId: 'pc-1', arguments: { q: 's' } })
      // Flag must be set synchronously before the await resolves.
      expect(c.perToolApproving.value[7]).toBe(true)
      await approvePromise
      expect(c.perToolApproving.value[7]).toBe(false)
      expect(taskStoreMock.approveTask).toHaveBeenCalledWith(1, [
        { provider_call_id: 'pc-1', arguments: { q: 's' } },
      ])
      expect(toastMock.success).toHaveBeenCalledWith('Tool "web_search" approved.')
      expect(taskStoreMock.startDetailPolling).toHaveBeenCalledWith(1)
      expect(onAfterMutation).toHaveBeenCalled()
    })

    it('handles unknown provider_call_id (no in-flight flag)', async () => {
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onApproveOne({ providerCallId: 'unknown', arguments: {} })
      expect(Object.keys(c.perToolApproving.value)).toHaveLength(0)
      expect(toastMock.success).toHaveBeenCalledWith('Tool "" approved.')
    })

    it('surfaces errors via toast and clears the in-flight flag', async () => {
      pendingToolCallsRef.value = [{ id: 7, provider_call_id: 'pc-1', tool_name: 'web_search' }]
      const { ApiError } = await import('@/api/client')
      taskStoreMock.approveTask.mockRejectedValueOnce(new ApiError('denied'))
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onApproveOne({ providerCallId: 'pc-1', arguments: {} })
      expect(c.perToolApproving.value[7]).toBe(false)
      expect(toastMock.error).toHaveBeenCalledWith('denied')
    })
  })

  describe('onRejectOne', () => {
    it('looks up the tool, marks it rejecting, and rejects', async () => {
      pendingToolCallsRef.value = [
        { id: 9, provider_call_id: 'pc-2', tool_name: 'send_email' },
      ]
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      const promise = c.onRejectOne({ providerCallId: 'pc-2', reason: 'wrong recipient' })
      expect(c.perToolRejecting.value[9]).toBe(true)
      await promise
      expect(c.perToolRejecting.value[9]).toBe(false)
      expect(taskStoreMock.rejectTask).toHaveBeenCalledWith(1, 'wrong recipient')
      expect(toastMock.success).toHaveBeenCalledWith('Tool "send_email" rejected.')
      expect(taskStoreMock.startDetailPolling).toHaveBeenCalledWith(1)
      expect(onAfterMutation).toHaveBeenCalled()
    })

    it('surfaces errors via toast and clears the in-flight flag', async () => {
      pendingToolCallsRef.value = [{ id: 9, provider_call_id: 'pc-2', tool_name: 'send_email' }]
      const { ApiError } = await import('@/api/client')
      taskStoreMock.rejectTask.mockRejectedValueOnce(new ApiError('network'))
      const c = useTaskChatApprovals(taskId, onAfterMutation)
      await c.onRejectOne({ providerCallId: 'pc-2', reason: 'x' })
      expect(c.perToolRejecting.value[9]).toBe(false)
      expect(toastMock.error).toHaveBeenCalledWith('network')
    })
  })
})
