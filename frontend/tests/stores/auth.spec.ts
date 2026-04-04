import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { describe, it, expect, beforeEach, vi } from 'vitest'

// Mock the api module
vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import { api } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

describe('useAuthStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('init', () => {
    it('sets user from /auth/me on success', async () => {
      const mockUser = { id: 1, email: 'test@example.com' }
      mockApi.get.mockResolvedValueOnce(mockUser)

      const store = useAuthStore()
      const promise = store.init()
      await promise

      expect(store.user).toEqual(mockUser)
      expect(store.initialized).toBe(true)
    })

    it('sets user to null on error', async () => {
      mockApi.get.mockRejectedValueOnce(new Error('Unauthorized'))

      const store = useAuthStore()
      await store.init()

      expect(store.user).toBe(null)
      expect(store.initialized).toBe(true)
    })

    it('deduplicates concurrent init calls by calling API only once', async () => {
      // Make the mock return an unresolved promise so both calls overlap
      let resolve: (v: unknown) => void
      const pendingPromise = new Promise((r) => { resolve = r })
      mockApi.get.mockReturnValue(pendingPromise as any)

      const store = useAuthStore()

      // Fire two init calls synchronously — second call should not hit the API
      store.init()
      store.init()

      // Only one API call due to deduplication
      expect(mockApi.get).toHaveBeenCalledTimes(1)

      // Resolve the pending promise
      resolve!({ id: 1, email: 'a@b.com' })
      await pendingPromise

      expect(store.user).toEqual({ id: 1, email: 'a@b.com' })
      expect(store.initialized).toBe(true)
    })
  })

  describe('login', () => {
    it('sets user on success', async () => {
      const mockUser = { id: 1, email: 'test@example.com' }
      mockApi.post.mockResolvedValueOnce(mockUser)

      const store = useAuthStore()
      await store.login('test@example.com', 'password')

      expect(mockApi.post).toHaveBeenCalledWith('/auth/login', {
        email: 'test@example.com',
        password: 'password',
      })
      expect(store.user).toEqual(mockUser)
    })
  })

  describe('logout', () => {
    it('clears user state optimistically', async () => {
      const store = useAuthStore()
      store.user = { id: 1, email: 'test@example.com' }
      store.initialized = true

      mockApi.post.mockResolvedValueOnce(undefined)

      await store.logout()

      expect(store.user).toBe(null)
      expect(store.initialized).toBe(false)
    })
  })

  describe('register', () => {
    it('sets user on success', async () => {
      const mockUser = { id: 2, email: 'new@example.com' }
      mockApi.post.mockResolvedValueOnce(mockUser)

      const store = useAuthStore()
      await store.register('new@example.com', 'password123')

      expect(mockApi.post).toHaveBeenCalledWith('/auth/register', {
        email: 'new@example.com',
        password: 'password123',
      })
      expect(store.user).toEqual(mockUser)
    })
  })
})
