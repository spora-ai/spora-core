import { setActivePinia, createPinia } from 'pinia'
import { useUsersStore } from '@/stores/users'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(
      public readonly code: string,
      message: string,
      public readonly status: number,
    ) {
      super(message)
    }
  },
}))

import { api, ApiError } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

const mockUser = {
  id: 1,
  email: 'admin@example.com',
  username: 'admin',
  is_admin: true,
  roles: ['admin'],
  created_at: '2026-01-01T00:00:00Z',
  registered: '2026-01-01T00:00:00Z',
  suspended: false,
}

describe('useUsersStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchUsers', () => {
    it('fetches users and sets loading state', async () => {
      const paginatedResult = {
        data: [mockUser],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
      }
      mockApi.get.mockResolvedValueOnce(paginatedResult)

      const store = useUsersStore()
      const result = await store.fetchUsers()

      expect(store.users).toEqual([mockUser])
      expect(store.loading).toBe(false)
      expect(result.users).toEqual([mockUser])
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('SERVER_ERROR', 'Failed to load', 500))

      const store = useUsersStore()
      await expect(store.fetchUsers()).rejects.toThrow(ApiError)
      expect(store.error).toBe('Failed to load')
    })

    it('fetches paginated users', async () => {
      const paginatedResult = {
        data: [mockUser, { ...mockUser, id: 2 }],
        meta: { current_page: 2, last_page: 3, per_page: 15, total: 42 },
      }
      mockApi.get.mockResolvedValueOnce(paginatedResult)

      const store = useUsersStore()
      const result = await store.fetchUsers(2)

      expect(mockApi.get).toHaveBeenCalledWith('/users?page=2')
      expect(result.current_page).toBe(2)
      expect(result.total).toBe(42)
    })
  })

  describe('createUser', () => {
    it('posts to /users and prepends to users list', async () => {
      mockApi.post.mockResolvedValueOnce({ user: mockUser })

      const store = useUsersStore()
      const result = await store.createUser({ email: 'admin@example.com', password: 'secret' })

      expect(mockApi.post).toHaveBeenCalledWith('/users', { email: 'admin@example.com', password: 'secret' })
      expect(store.users[0]).toEqual(mockUser)
      expect(result).toEqual(mockUser)
    })

    it('sets error and rethrows on failure', async () => {
      mockApi.post.mockRejectedValueOnce(new ApiError('VALIDATION_ERROR', 'Email already exists', 422))

      const store = useUsersStore()
      await expect(store.createUser({ email: 'dup@example.com', password: 'secret' })).rejects.toThrow(ApiError)
      expect(store.error).toBe('Email already exists')
    })
  })

  describe('updateUser', () => {
    it('patches user and updates in list', async () => {
      const updated = { ...mockUser, username: 'newname', is_admin: false }
      mockApi.patch.mockResolvedValueOnce({ user: updated })

      const store = useUsersStore()
      store.users = [mockUser]

      const result = await store.updateUser(1, { username: 'newname', is_admin: false })

      expect(mockApi.patch).toHaveBeenCalledWith('/users/1', { username: 'newname', is_admin: false })
      expect(store.users[0].username).toBe('newname')
      expect(store.users[0].is_admin).toBe(false)
      expect(result.username).toBe('newname')
    })

    it('replaces user in list when id matches', async () => {
      const otherUser = { ...mockUser, id: 99 }
      const updated = { ...mockUser, suspended: true }
      mockApi.patch.mockResolvedValueOnce({ user: updated })

      const store = useUsersStore()
      store.users = [otherUser, mockUser]

      await store.updateUser(1, { suspended: true })

      const updatedUser = store.users.find(u => u.id === 1)
      expect(updatedUser?.suspended).toBe(true)
    })
  })

  describe('deleteUser', () => {
    it('deletes from API and removes from users list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useUsersStore()
      store.users = [mockUser, { ...mockUser, id: 2 }]

      await store.deleteUser(1)

      expect(mockApi.delete).toHaveBeenCalledWith('/users/1')
      expect(store.users).toHaveLength(1)
      expect(store.users[0].id).toBe(2)
    })
  })

  describe('grantRole', () => {
    it('posts to /users/{id}/roles and updates user in list', async () => {
      const withRole = { ...mockUser, roles: ['admin', 'editor'] }
      mockApi.post.mockResolvedValueOnce({ user: withRole })

      const store = useUsersStore()
      store.users = [mockUser]

      const result = await store.grantRole(1, 'editor')

      expect(mockApi.post).toHaveBeenCalledWith('/users/1/roles', { role: 'editor' })
      expect(store.users[0].roles).toContain('editor')
      expect(result.roles).toContain('editor')
    })
  })

  describe('revokeRole', () => {
    it('deletes from /users/{id}/roles/{role} and updates user in list', async () => {
      const withoutAdmin = { ...mockUser, roles: [] }
      mockApi.delete.mockResolvedValueOnce({ user: withoutAdmin })

      const store = useUsersStore()
      store.users = [mockUser]

      const result = await store.revokeRole(1, 'admin')

      expect(mockApi.delete).toHaveBeenCalledWith('/users/1/roles/admin')
      expect(store.users[0].roles).not.toContain('admin')
      expect(result.roles).not.toContain('admin')
    })
  })
})
