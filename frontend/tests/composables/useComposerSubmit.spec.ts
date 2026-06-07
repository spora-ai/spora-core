/**
 * useComposerSubmit — submit flow for ComposerInput.
 *
 * Covers: empty-prompt early return, success navigation, ApiError path, and
 * the generic-error fallback. The draft-clear call is verified.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

const toastMock = { error: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

const createTaskMock = vi.fn()
const clearDraftMock = vi.fn()

vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({ createTaskForAgent: createTaskMock }),
}))

const currentAgentRef = ref<unknown>(null)
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get currentAgent() { return currentAgentRef.value },
    clearComposerDraft: clearDraftMock,
  }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class FakeApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import { useComposerSubmit } from '@/composables/useComposerSubmit'

beforeEach(() => {
  setActivePinia(createPinia())
  pushMock.mockReset()
  createTaskMock.mockReset()
  createTaskMock.mockResolvedValue({ id: 99 })
  clearDraftMock.mockReset()
  toastMock.error.mockReset()
})

describe('useComposerSubmit', () => {
  it('does nothing when prompt is empty/whitespace', async () => {
    const c = useComposerSubmit(1)
    await c.submit('   ')
    expect(createTaskMock).not.toHaveBeenCalled()
    expect(c.submitting.value).toBe(false)
    expect(c.error.value).toBe(null)
  })

  it('creates a task, clears the draft, and navigates on success', async () => {
    const c = useComposerSubmit(1)
    await c.submit('hello world')
    expect(createTaskMock).toHaveBeenCalledWith(1, 'hello world')
    expect(clearDraftMock).toHaveBeenCalledWith(1)
    expect(pushMock).toHaveBeenCalledWith({ name: 'task', params: { id: 99 } })
    expect(c.submitting.value).toBe(false)
    expect(c.error.value).toBe(null)
  })

  it('trims surrounding whitespace before calling the store', async () => {
    const c = useComposerSubmit(1)
    await c.submit('  hi  ')
    expect(createTaskMock).toHaveBeenCalledWith(1, 'hi')
  })

  it('surfaces an ApiError message via the error ref', async () => {
    const { ApiError } = await import('@/api/client')
    createTaskMock.mockRejectedValueOnce(new ApiError('rate limited'))
    const c = useComposerSubmit(1)
    await c.submit('hi')
    expect(c.error.value).toBe('rate limited')
    expect(c.submitting.value).toBe(false)
  })

  it('falls back to a generic message for non-ApiError rejections', async () => {
    createTaskMock.mockRejectedValueOnce(new Error('boom'))
    const c = useComposerSubmit(1)
    await c.submit('hi')
    expect(c.error.value).toBe('Failed to start task.')
    expect(c.submitting.value).toBe(false)
  })

  it('clears error on a subsequent successful submit', async () => {
    const c = useComposerSubmit(1)
    createTaskMock.mockRejectedValueOnce(new Error('boom'))
    await c.submit('hi')
    expect(c.error.value).toBe('Failed to start task.')
    await c.submit('hi')
    expect(c.error.value).toBe(null)
  })
})
