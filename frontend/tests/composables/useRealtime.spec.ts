import { describe, it, expect, vi } from 'vitest'

/**
 * useRealtime integration tests.
 *
 * The useRealtime composable uses a module-level singleton for the EventSource
 * connection, which makes it challenging to test in isolation without modifying
 * production code. These tests verify the SSE-active path.
 *
 * The topic structure (user/{userId}/tasks, user/{userId}/notifications) is validated by:
 *   - SseControllerTest (subscriber JWT claims)
 *   - MercurePublisherTest (publish topic format)
 *   - tasks.spec.ts applyTaskUpdate tests (SSE data handling)
 */

const prependFromSSE = vi.fn()
const applyTaskUpdate = vi.fn()
const applySseTaskEvent = vi.fn()
const startListPolling = vi.fn()

vi.mock('@/api/client', () => ({
  api: { get: vi.fn() },
}))

vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ prependFromSSE, fetchNotifications: vi.fn() }),
}))

vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({ applyTaskUpdate, startListPolling }),
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({ applySseTaskEvent }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1, email: 'test@example.com' }, initialized: true }),
}))

import { api } from '@/api/client'

describe('useRealtime integration', () => {
  it('calls /sse/auth when SSE is active', async () => {
    vi.clearAllMocks()
    vi.mocked(api).get
      .mockResolvedValueOnce({ active: true, hubUrl: '/.well-known/mercure' })
      .mockResolvedValueOnce({ hubUrl: '/.well-known/mercure', token: 'test-token' })

    const { useRealtime } = await import('@/composables/useRealtime')
    useRealtime()
    await new Promise(r => setTimeout(r, 0))

    expect(api.get).toHaveBeenCalledWith('/sse/auth')
  })

  it('does not start polling when SSE is active', async () => {
    vi.clearAllMocks()
    vi.mocked(api).get
      .mockResolvedValueOnce({ active: true, hubUrl: '/.well-known/mercure' })
      .mockResolvedValueOnce({ hubUrl: '/.well-known/mercure', token: 'test-token' })

    const { useRealtime } = await import('@/composables/useRealtime')
    useRealtime()
    await new Promise(r => setTimeout(r, 0))

    expect(startListPolling).not.toHaveBeenCalled()
  })
})
