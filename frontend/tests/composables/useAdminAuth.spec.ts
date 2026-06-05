/**
 * useAdminAuth — exposes isAdmin/isForbidden computed refs sourced from auth store.
 */
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'

// Reactive holder for the mocked auth state.
const mockUser = ref<{ id: number; is_admin: boolean } | null>(null)

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockUser.value
    },
  }),
}))

import { useAdminAuth } from '@/composables/useAdminAuth'

describe('useAdminAuth', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockUser.value = null
  })

  it('isAdmin is false when no user is logged in', () => {
    const { isAdmin } = useAdminAuth()
    expect(isAdmin.value).toBe(false)
  })

  it('isForbidden is true when no user is logged in', () => {
    const { isForbidden } = useAdminAuth()
    expect(isForbidden.value).toBe(true)
  })

  it('isAdmin reflects user.is_admin', () => {
    mockUser.value = { id: 1, is_admin: true }
    const { isAdmin, isForbidden } = useAdminAuth()
    expect(isAdmin.value).toBe(true)
    expect(isForbidden.value).toBe(false)
  })

  it('isForbidden is true when user is not admin', () => {
    mockUser.value = { id: 1, is_admin: false }
    const { isAdmin, isForbidden } = useAdminAuth()
    expect(isAdmin.value).toBe(false)
    expect(isForbidden.value).toBe(true)
  })

  it('isAdmin defaults to false when is_admin missing', () => {
    mockUser.value = { id: 1 } as { id: number; is_admin: boolean }
    const { isAdmin } = useAdminAuth()
    expect(isAdmin.value).toBe(false)
  })
})
