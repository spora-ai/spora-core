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
      const result = await api.get<{
        users: User[]
        meta: { current_page: number; last_page: number; per_page: number; total: number }
      }>(`/users?page=${page}`)
      users.value = result.users
      return {
        users: result.users,
        current_page: result.meta.current_page,
        last_page: result.meta.last_page,
        per_page: result.meta.per_page,
        total: result.meta.total,
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

  return { users, loading, saving, error, fetchUsers, createUser, updateUser, deleteUser, grantRole, revokeRole }
})
