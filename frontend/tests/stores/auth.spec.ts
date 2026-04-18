import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { describe, it, expect, beforeEach, vi } from 'vitest'

// Mock the api module
vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(public readonly code: string, message: string, public readonly status: number) {
      super(message)
      this.name = 'ApiError'
    }
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
      mockApi.get.mockResolvedValueOnce({ user: { id: 1, email: 'test@example.com' } })

      const store = useAuthStore()
      const promise = store.init()
      await promise

      expect(store.user).toEqual({ id: 1, email: 'test@example.com' })
      expect(store.initialized).toBe(true)
    })

    it('sets user to null on 401 error', async () => {
      const { ApiError } = await import('@/api/client')
      mockApi.get.mockRejectedValueOnce(new ApiError('UNAUTHENTICATED', 'Not logged in', 401))

      const store = useAuthStore()
      await store.init()

      expect(store.user).toBe(null)
      expect(store.initialized).toBe(true)
    })

    it('deduplicates concurrent init calls by calling API only once', async () => {
      let resolve: (v: unknown) => void
      const pendingPromise = new Promise((r) => { resolve = r })
      mockApi.get.mockReturnValue(pendingPromise as any)

      const store = useAuthStore()

      store.init()
      store.init()

      expect(mockApi.get).toHaveBeenCalledTimes(1)

      resolve!({ user: { id: 1, email: 'a@b.com' } })
      await pendingPromise

      expect(store.user).toEqual({ id: 1, email: 'a@b.com' })
      expect(store.initialized).toBe(true)
    })
  })

  describe('login', () => {
    it('sets user on success', async () => {
      mockApi.post.mockResolvedValueOnce({ id: 1, email: 'test@example.com' })

      const store = useAuthStore()
      await store.login('test@example.com', 'password')

      expect(mockApi.post).toHaveBeenCalledWith('/auth/login', {
        email: 'test@example.com',
        password: 'password',
      })
      expect(store.user).toEqual({ id: 1, email: 'test@example.com' })
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
      expect(mockApi.post).toHaveBeenCalledTimes(1)
      expect(mockApi.post.mock.calls[0][0]).toBe('/auth/logout')
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

  describe('changePassword', () => {
    it('calls PATCH /auth/password with correct body', async () => {
      mockApi.patch.mockResolvedValueOnce({ message: 'Password updated' })

      const store = useAuthStore()
      await store.changePassword('oldPass123', 'newPass456')

      expect(mockApi.patch).toHaveBeenCalledWith('/auth/password', {
        current_password: 'oldPass123',
        new_password: 'newPass456',
      })
    })
  })

  describe('updateAccount', () => {
    it('calls PATCH /auth/account and updates user', async () => {
      mockApi.patch.mockResolvedValueOnce({
        user: { id: 1, email: 'test@example.com', username: 'newname' },
      })

      const store = useAuthStore()
      await store.updateAccount('newname')

      expect(mockApi.patch).toHaveBeenCalledWith('/auth/account', { username: 'newname' })
      expect(store.user).toEqual({ id: 1, email: 'test@example.com', username: 'newname' })
    })
  })
})
