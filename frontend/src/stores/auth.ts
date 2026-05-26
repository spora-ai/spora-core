import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { User } from '@/types/user'

export { type User }

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const initialized = ref(false)
  const initError = ref<Error | null>(null)

  function normalizeUser(raw: User | null): User | null {
    if (raw === null) return null
    const roles: string[] = []
    if (raw.is_admin) roles.push('ADMIN')
    return { ...raw, roles }
  }

  let initPromise: Promise<void> | null = null

  /** Called once on app boot to restore session from the server cookie. */
  function init(): Promise<void> {
    if (initPromise !== null) return initPromise

    initPromise = (async () => {
      try {
        initError.value = null
        const res = await api.get<{ user: User }>('/auth/me')
        user.value = normalizeUser(res.user)
      } catch (e) {
        user.value = null
        const isUnauthenticated = e instanceof ApiError && e.status === 401
        if (!isUnauthenticated) {
          initError.value = e instanceof Error ? e : new Error(String(e))
        }
      } finally {
        initialized.value = true
        if (initError.value !== null) {
          initPromise = null
        }
      }
    })()

    return initPromise
  }

  async function login(email: string, password: string): Promise<void> {
    user.value = normalizeUser((await api.post<{ user: User }>('/auth/login', { email, password })).user)
    initialized.value = true
  }

  /**
   * Register a new user account.
   * Does NOT log the user in — caller must handle the "verify email" state.
   */
  async function register(email: string, password: string, confirmPassword: string, displayName: string): Promise<{ user_id: number; email: string }> {
    const res = await api.post<{ user: { id: number; email: string } }>('/auth/register', { email, password, confirm_password: confirmPassword, display_name: displayName })
    return { user_id: res.user.id, email: res.user.email }
  }

  async function logout(): Promise<void> {
    user.value = null
    initPromise = null
    initialized.value = false
    await api.post('/auth/logout').catch(() => {})
  }

  async function changePassword(current: string, next: string): Promise<void> {
    await api.patch('/auth/password', { current_password: current, new_password: next })
  }

  async function updateAccount(name: string): Promise<void> {
    const updated = await api.patch<{ user: User }>('/auth/account', { name })
    user.value = normalizeUser(updated.user)
  }

  async function resendVerification(email: string): Promise<void> {
    await api.post('/auth/verification/resend', { email })
  }

  async function forgotPassword(email: string): Promise<void> {
    await api.post('/auth/forgot-password', { email })
  }

  async function resetPassword(selector: string, token: string, password: string): Promise<void> {
    await api.post('/auth/reset-password', { selector, token, password })
  }

  async function changeEmail(newEmail: string): Promise<void> {
    await api.post('/auth/email/change-request', { email: newEmail })
  }

  return {
    user,
    initialized,
    initError,
    init,
    login,
    register,
    logout,
    changePassword,
    updateAccount,
    resendVerification,
    forgotPassword,
    resetPassword,
    changeEmail,
  }
})

