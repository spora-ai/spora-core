import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'

// Create mock store values that tests can override
// IMPORTANT: use Vue refs to match real Pinia behavior (refs are always truthy objects)
import { ref } from 'vue'

const mockStoreState = {
  csrfToken: ref<string | null>('test-token'),
  user: ref<{ id: number; email: string } | null>({ id: 1, email: 'a@b.com' }),
  initialized: ref<boolean>(true),
}

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    csrfToken: mockStoreState.csrfToken,
    user: mockStoreState.user,
    initialized: mockStoreState.initialized,
    $patch: vi.fn((patch: { csrfToken: string | null }) => {
      mockStoreState.csrfToken.value = patch.csrfToken
    }),
  }),
}))

// Spy on fetch to inspect headers
const fetchSpy = vi.spyOn(global, 'fetch')

function mockFetch(response: Partial<Response> & { body?: unknown } = {}) {
  fetchSpy.mockResolvedValueOnce({
    ok: true,
    status: 200,
    statusText: 'OK',
    headers: new Headers({ 'content-type': 'application/json' }),
    text: async () => JSON.stringify({ data: response.body ?? {} }),
    ...response,
  } as unknown as Response)
}

function mockFetchSequence(responses: Partial<Response>[]) {
  responses.forEach((r) => {
    const body = r.body
    fetchSpy.mockResolvedValueOnce({
      ok: r.ok ?? true,
      status: r.status ?? 200,
      statusText: String(r.status ?? 'OK'),
      headers: new Headers({ 'content-type': 'application/json' }),
      text: async () => JSON.stringify(body ? { data: body } : {}),
      ...r,
    } as unknown as Response)
  })
}

// Import api AFTER vi.mock so the mock is active
import { api } from '@/api/client'

describe('CSRF token injection', () => {
  beforeEach(() => {
    fetchSpy.mockReset()
    setActivePinia(createPinia())
    // Reset to default logged-in state with token (using refs to match real Pinia behavior)
    mockStoreState.csrfToken.value = 'test-token'
    mockStoreState.user.value = { id: 1, email: 'a@b.com' }
    mockStoreState.initialized.value = true
  })

  describe('state-changing methods', () => {
    it('sends X-CSRF-Token header on POST when token is in store', async () => {
      mockFetch({ body: {} })

      await api.post('/tasks', { agent_id: 1, prompt: 'hello' })

      expect(fetchSpy).toHaveBeenCalledTimes(1)
      const [, init] = fetchSpy.mock.calls[0]
      expect(init.headers).toHaveProperty('X-CSRF-Token', 'test-token')
    })

    it('sends X-CSRF-Token header on PATCH', async () => {
      mockFetch({ body: {} })

      await api.patch('/auth/password', { current_password: 'old', new_password: 'new' })

      const [, init] = fetchSpy.mock.calls[0]
      expect(init.headers).toHaveProperty('X-CSRF-Token', 'test-token')
    })

    it('sends X-CSRF-Token header on PUT', async () => {
      mockFetch({ body: {} })

      await api.put('/agents/1', { name: 'Updated Agent' })

      const [, init] = fetchSpy.mock.calls[0]
      expect(init.headers).toHaveProperty('X-CSRF-Token', 'test-token')
    })

    it('sends X-CSRF-Token header on DELETE', async () => {
      mockFetch({ body: {} })

      await api.delete('/tasks/5')

      const [, init] = fetchSpy.mock.calls[0]
      expect(init.headers).toHaveProperty('X-CSRF-Token', 'test-token')
    })

    it('fetches fresh token from /auth/me and uses it for the request', async () => {
      // Set up state: token missing (null), user logged in
      mockStoreState.csrfToken.value = null

      // Sequence:
      // 1. GET /auth/me → returns fresh token (called before the POST)
      // 2. POST /tasks with new token → 200 success
      mockFetchSequence([
        { body: { user: { id: 1 }, csrf_token: 'fresh-token-xyz' } },
        { body: { task: { id: 1 } } },
      ])

      const result = await api.post<{ task: { id: number } }>('/tasks', {})

      // Should have made 2 fetch calls: GET /auth/me (to get token), then POST with new token
      expect(fetchSpy).toHaveBeenCalledTimes(2)
      expect(fetchSpy.mock.calls[0][0]).toContain('/auth/me')

      // The POST should have the new token
      const [, postInit] = fetchSpy.mock.calls[1]
      expect(postInit.headers).toHaveProperty('X-CSRF-Token', 'fresh-token-xyz')

      // Store should be updated
      expect(mockStoreState.csrfToken.value).toBe('fresh-token-xyz')
      expect(result).toEqual({ task: { id: 1 } })
    })

    it('does NOT fetch fresh token when user is not logged in', async () => {
      mockStoreState.csrfToken.value = null
      mockStoreState.user.value = null
      mockStoreState.initialized.value = false

      mockFetch({ body: {} })

      await api.post('/tasks', { agent_id: 1, prompt: 'hello' })

      // Should have made only the one POST request — no /auth/me call
      expect(fetchSpy).toHaveBeenCalledTimes(1)
      const [, init] = fetchSpy.mock.calls[0]
      // Verify: X-CSRF-Token must not be set (neither as string nor as Ref object)
      expect(init.headers['X-CSRF-Token']).toBeUndefined()
    })
  })

  describe('safe methods', () => {
    it('does NOT send X-CSRF-Token header on GET', async () => {
      mockFetch({ body: { tasks: [] } })

      await api.get('/tasks')

      expect(fetchSpy).toHaveBeenCalledTimes(1)
      const [, init] = fetchSpy.mock.calls[0]
      expect(init.headers).not.toHaveProperty('X-CSRF-Token')
    })
  })

  describe('session expired handler', () => {
    it('calls sessionExpiredHandler on 401 UNAUTHENTICATED when user is logged in', async () => {
      const { setupSessionHandler } = await import('@/api/client')
      const handler = vi.fn()
      setupSessionHandler(handler)

      fetchSpy.mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        headers: new Headers({ 'content-type': 'application/json' }),
        text: async () => JSON.stringify({ error: { code: 'UNAUTHENTICATED', message: 'Session expired' } }),
      } as unknown as Response)

      await expect(api.post('/tasks', {})).rejects.toThrow()

      expect(handler).toHaveBeenCalledTimes(1)
    })

    it('does NOT call sessionExpiredHandler on 401 when user is not logged in', async () => {
      const { setupSessionHandler } = await import('@/api/client')
      const handler = vi.fn()
      setupSessionHandler(handler)

      mockStoreState.csrfToken.value = null
      mockStoreState.user.value = null

      fetchSpy.mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        headers: new Headers({ 'content-type': 'application/json' }),
        text: async () => JSON.stringify({ error: { code: 'UNAUTHENTICATED', message: 'Session expired' } }),
      } as unknown as Response)

      await expect(api.post('/tasks', {})).rejects.toThrow()

      expect(handler).not.toHaveBeenCalled()
    })
  })
})
