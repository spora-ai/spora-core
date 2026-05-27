/**
 * users store — admin user management.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { User, PaginatedUsers, CreateUserPayload, UpdateUserPayload } from '@/types/user'

export const useUsersStore = defineStore('users', () => {
  const users = ref<User[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function fetchUsers(page = 1): Promise<PaginatedUsers> {
    loading.value = true
    error.value = null
    try {
      const raw = await api.get<{
        data: User[]
        meta: { current_page: number; last_page: number; per_page: number; total: number }
      }>(`/users?page=${page}`)
      const result = raw.data ?? raw
      users.value = result as User[]
      const meta = raw.meta ?? { current_page: 1, last_page: 1, per_page: 20, total: 0 }
      return {
        users: result as User[],
        current_page: meta.current_page,
        last_page: meta.last_page,
        per_page: meta.per_page,
        total: meta.total,
      }
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load users.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function createUser(payload: CreateUserPayload): Promise<User> {
    saving.value = true
    error.value = null
    try {
      const result = await api.post<{ user: User }>('/users', payload)
      users.value.unshift(result.user)
      return result.user
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to create user.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateUser(id: number, data: UpdateUserPayload): Promise<User> {
    saving.value = true
    error.value = null
    try {
      const result = await api.patch<{ user: User }>(`/users/${id}`, data)
      const idx = users.value.findIndex((u) => u.id === id)
      if (idx !== -1) users.value[idx] = result.user
      return result.user
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to update user.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteUser(id: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.delete(`/users/${id}`)
      users.value = users.value.filter((u) => u.id !== id)
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to delete user.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function grantRole(id: number, role: string): Promise<User> {
    saving.value = true
    error.value = null
    try {
      const result = await api.post<{ user: User }>(`/users/${id}/roles`, { role })
      const idx = users.value.findIndex((u) => u.id === id)
      if (idx !== -1) users.value[idx] = result.user
      return result.user
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to grant role.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function revokeRole(id: number, role: string): Promise<User> {
    saving.value = true
    error.value = null
    try {
      const result = await api.delete<{ user: User }>(`/users/${id}/roles/${role}`)
      const idx = users.value.findIndex((u) => u.id === id)
      if (idx !== -1) users.value[idx] = result.user
      return result.user
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to revoke role.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function setVerified(id: number, verified: boolean): Promise<User> {
    saving.value = true
    error.value = null
    try {
      const result = await api.patch<{ user: User }>(`/users/${id}`, { verified })
      const idx = users.value.findIndex((u) => u.id === id)
      if (idx !== -1) users.value[idx] = result.user
      return result.user
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to update verification status.'
      throw e
    } finally {
      saving.value = false
    }
  }

  return { users, loading, saving, error, fetchUsers, createUser, updateUser, deleteUser, grantRole, revokeRole, setVerified }
})
