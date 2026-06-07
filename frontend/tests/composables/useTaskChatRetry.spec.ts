/**
 * useTaskChatRetry — derived banner state + retry/cancel handlers.
 *
 * Mocks Pinia stores (active task + current agent) and vue-router so we can
 * exercise the composable in isolation, including the success and error
 * branches of the action handlers.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

// Mock vue-router before importing the composable.
const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

const toastMock = { error: vi.fn(), success: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

const activeTaskRef = ref<Record<string, unknown> | null>(null)
const taskStoreMock = {
  get activeTask() { return activeTaskRef.value },
  cancelRetryChain: vi.fn(),
  fetchTask: vi.fn(),
  retryTask: vi.fn(),
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

// Stub the ApiError class so the composable's `instanceof ApiError` check works.
vi.mock('@/api/client', () => ({
  ApiError: class FakeApiError extends Error {
    constructor(message: string) {
      super(message)
      this.name = 'ApiError'
    }
  },
}))

import { useTaskChatRetry } from '@/composables/useTaskChatRetry'
import { ApiError as FakeApiError } from '@/api/client'

function setActiveTask(overrides: Record<string, unknown> = {}): void {
  activeTaskRef.value = {
    id: 1,
    status: 'FAILED',
    step_count: 5,
    max_steps: 10,
    failure_reason: null,
    error_code: null,
    error_message: null,
    retry_of_task_id: null,
    max_retries: 0,
    retry_count: 0,
    retry_after: null,
    ...overrides,
  }
}

function setAgent(allowContinuation: boolean | undefined = true): void {
  currentAgentRef.value = { allow_continuation: allowContinuation }
}

beforeEach(() => {
  setActivePinia(createPinia())
  activeTaskRef.value = null
  currentAgentRef.value = null
  // Clear call history but keep implementations.
  pushMock.mockClear()
  toastMock.error.mockClear()
  toastMock.success.mockClear()
  taskStoreMock.cancelRetryChain.mockClear()
  taskStoreMock.cancelRetryChain.mockResolvedValue(undefined)
  taskStoreMock.fetchTask.mockClear()
  taskStoreMock.fetchTask.mockResolvedValue(undefined)
  taskStoreMock.retryTask.mockClear()
  taskStoreMock.retryTask.mockResolvedValue({ id: 99 })
})

describe('useTaskChatRetry', () => {
  describe('banner visibility', () => {
    it('hides every banner when there is no active task', () => {
      const c = useTaskChatRetry()
      expect(c.showMaxStepsBanner.value).toBe(false)
      expect(c.showRetryBanner.value).toBe(false)
      expect(c.showNonRetryableErrorBanner.value).toBe(false)
      expect(c.showCountdown.value).toBe(false)
    })

    it('shows max-steps banner only when failed, max-steps, and agent allows continuation', () => {
      setAgent(true)
      setActiveTask({ status: 'FAILED', failure_reason: 'Max steps reached.' })
      const c = useTaskChatRetry()
      expect(c.showMaxStepsBanner.value).toBe(true)
    })

    it('hides max-steps banner when agent disallows continuation', () => {
      setAgent(false)
      setActiveTask({ status: 'FAILED', failure_reason: 'Max steps reached.' })
      const c = useTaskChatRetry()
      expect(c.showMaxStepsBanner.value).toBe(false)
    })

    it('shows retry banner for RATE_LIMIT when no retry_after is set', () => {
      setAgent(true)
      setActiveTask({ status: 'FAILED', error_code: 'RATE_LIMIT' })
      const c = useTaskChatRetry()
      expect(c.showRetryBanner.value).toBe(true)
      expect(c.showNonRetryableErrorBanner.value).toBe(false)
    })

    it('shows retry banner when retry_after is set but auto-retry is disabled (max_retries=0)', () => {
      setAgent(true)
      setActiveTask({
        status: 'FAILED',
        error_code: 'RATE_LIMIT',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        max_retries: 0,
        retry_count: 0,
      })
      const c = useTaskChatRetry()
      expect(c.autoRetryDisabled.value).toBe(true)
      expect(c.showRetryBanner.value).toBe(true)
    })

    it('hides retry banner when retry_after is set, auto-retry configured, and retries not exhausted', () => {
      setAgent(true)
      setActiveTask({
        status: 'FAILED',
        error_code: 'RATE_LIMIT',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        max_retries: 3,
        retry_count: 0,
        retry_of_task_id: null,
      })
      const c = useTaskChatRetry()
      expect(c.autoRetryConfigured.value).toBe(true)
      expect(c.canAutoRetry.value).toBe(true)
      expect(c.showRetryBanner.value).toBe(false)
    })

    it('hides retry banner for a retry task (retry_of_task_id set) even when retry_after is set', () => {
      setAgent(true)
      setActiveTask({
        status: 'FAILED',
        error_code: 'RATE_LIMIT',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        retry_of_task_id: 42,
        max_retries: 3,
        retry_count: 0,
      })
      const c = useTaskChatRetry()
      expect(c.autoRetryConfigured.value).toBe(false)
      expect(c.showRetryBanner.value).toBe(false)
    })

    it('hides retry banner for non-retryable codes (NO_LLM_CONFIGURATION, UNKNOWN)', () => {
      setAgent(true)
      setActiveTask({ status: 'FAILED', error_code: 'NO_LLM_CONFIGURATION' })
      const c = useTaskChatRetry()
      expect(c.showRetryBanner.value).toBe(false)
      expect(c.showNonRetryableErrorBanner.value).toBe(true)
    })

    it('respects errorBannerDismissed for both retry banners', () => {
      setAgent(true)
      setActiveTask({ status: 'FAILED', error_code: 'RATE_LIMIT' })
      const c = useTaskChatRetry()
      c.dismissBanner()
      expect(c.showRetryBanner.value).toBe(false)
    })

    it('hides banners when error_code is null', () => {
      setActiveTask({ status: 'FAILED', error_code: null })
      const c = useTaskChatRetry()
      expect(c.showRetryBanner.value).toBe(false)
      expect(c.showNonRetryableErrorBanner.value).toBe(false)
    })

    it('hides retry banner when status is not FAILED', () => {
      setActiveTask({ status: 'COMPLETED', error_code: 'RATE_LIMIT' })
      const c = useTaskChatRetry()
      expect(c.showRetryBanner.value).toBe(false)
    })
  })

  describe('countdown derived state', () => {
    it('shows countdown when retry_after is set on a failed task', () => {
      setActiveTask({ status: 'FAILED', retry_after: new Date(Date.now() + 60_000).toISOString() })
      const c = useTaskChatRetry()
      expect(c.showCountdown.value).toBe(true)
      expect(c.countdown.value).not.toBe('')
    })

    it('reports canAutoRetry when retries remain', () => {
      setActiveTask({
        status: 'FAILED',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        max_retries: 3,
        retry_count: 0,
      })
      const c = useTaskChatRetry()
      expect(c.canAutoRetry.value).toBe(true)
      expect(c.retriesExhausted.value).toBe(false)
      expect(c.autoRetryDisabled.value).toBe(false)
      expect(c.retryAttempt.value).toBe(1)
      expect(c.maxRetryAttempts.value).toBe(3)
    })

    it('reports retriesExhausted when retry_count >= max_retries', () => {
      setActiveTask({
        status: 'FAILED',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        max_retries: 2,
        retry_count: 2,
      })
      const c = useTaskChatRetry()
      expect(c.canAutoRetry.value).toBe(false)
      expect(c.retriesExhausted.value).toBe(true)
    })

    it('reports autoRetryDisabled for a fresh (non-retry) task with max_retries=0', () => {
      setActiveTask({
        status: 'FAILED',
        retry_after: new Date(Date.now() + 60_000).toISOString(),
        max_retries: 0,
        retry_count: 0,
      })
      const c = useTaskChatRetry()
      expect(c.autoRetryDisabled.value).toBe(true)
    })

    it('hides countdown when status is not FAILED', () => {
      setActiveTask({ status: 'COMPLETED', retry_after: new Date(Date.now() + 60_000).toISOString() })
      const c = useTaskChatRetry()
      expect(c.showCountdown.value).toBe(false)
    })
  })

  describe('non-retryable error message', () => {
    it('returns error_message by default', () => {
      setActiveTask({ status: 'FAILED', error_code: 'NO_LLM_CONFIGURATION', error_message: 'No LLM.' })
      const c = useTaskChatRetry()
      expect(c.nonRetryableErrorMessage.value).toBe('No LLM.')
    })

    it('returns failure_reason for UNKNOWN errors', () => {
      setActiveTask({
        status: 'FAILED',
        error_code: 'UNKNOWN',
        error_message: 'Generic message',
        failure_reason: 'Specific reason',
      })
      const c = useTaskChatRetry()
      expect(c.nonRetryableErrorMessage.value).toBe('Specific reason')
    })

    it('falls back to error_message for UNKNOWN errors when no failure_reason', () => {
      setActiveTask({ status: 'FAILED', error_code: 'UNKNOWN', error_message: 'Generic' })
      const c = useTaskChatRetry()
      expect(c.nonRetryableErrorMessage.value).toBe('Generic')
    })

    it('returns null when there is no task', () => {
      const c = useTaskChatRetry()
      expect(c.nonRetryableErrorMessage.value).toBeNull()
    })
  })

  describe('cancelRetryChain', () => {
    it('calls the store and refetches the task on success', async () => {
      setActiveTask()
      const c = useTaskChatRetry()
      await c.cancelRetryChain()
      expect(taskStoreMock.cancelRetryChain).toHaveBeenCalledWith(1)
      expect(taskStoreMock.fetchTask).toHaveBeenCalledWith(1)
      expect(c.cancelling.value).toBe(false)
    })

    it('surfaces an ApiError message via toast and clears the flag', async () => {
      setActiveTask()
      taskStoreMock.cancelRetryChain.mockRejectedValueOnce(new FakeApiError('boom'))
      const c = useTaskChatRetry()
      await c.cancelRetryChain()
      expect(toastMock.error).toHaveBeenCalledWith('boom')
      expect(c.cancelling.value).toBe(false)
    })

    it('shows a generic fallback for non-ApiError rejections', async () => {
      setActiveTask()
      taskStoreMock.cancelRetryChain.mockRejectedValueOnce(new Error('boom'))
      const c = useTaskChatRetry()
      await c.cancelRetryChain()
      expect(toastMock.error).toHaveBeenCalledWith('Failed to cancel retry.')
    })

    it('is a no-op when there is no active task', async () => {
      const c = useTaskChatRetry()
      await c.cancelRetryChain()
      expect(taskStoreMock.cancelRetryChain).not.toHaveBeenCalled()
    })
  })

  describe('retryNow', () => {
    it('dismisses the banner, calls retryTask, and navigates to the new task', async () => {
      setActiveTask()
      const c = useTaskChatRetry()
      await c.retryNow()
      expect(c.errorBannerDismissed.value).toBe(true)
      expect(taskStoreMock.retryTask).toHaveBeenCalledWith(1)
      expect(pushMock).toHaveBeenCalledWith({ name: 'task', params: { id: 99 } })
    })

    it('toasts the error and does not navigate on failure', async () => {
      setActiveTask()
      taskStoreMock.retryTask.mockRejectedValueOnce(new Error('nope'))
      const c = useTaskChatRetry()
      await c.retryNow()
      expect(toastMock.error).toHaveBeenCalledWith('Retry failed.')
      expect(pushMock).not.toHaveBeenCalled()
    })

    it('is a no-op when there is no active task', async () => {
      const c = useTaskChatRetry()
      await c.retryNow()
      expect(taskStoreMock.retryTask).not.toHaveBeenCalled()
    })
  })

  describe('dismissBanner', () => {
    it('sets errorBannerDismissed to true', () => {
      const c = useTaskChatRetry()
      expect(c.errorBannerDismissed.value).toBe(false)
      c.dismissBanner()
      expect(c.errorBannerDismissed.value).toBe(true)
    })
  })
})
