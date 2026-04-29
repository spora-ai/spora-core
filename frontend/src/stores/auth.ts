import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'

export interface User {
  id: number
  email: string
  username?: string
  is_admin?: boolean
  roles?: string[]
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const initialized = ref(false)
  const initError = ref<Error | null>(null)

  // Normalize is_admin from the backend into roles so existing checks work.
  function normalizeUser(raw: User | null): User | null {
    if (raw === null) return null
    const roles: string[] = []
    if (raw.is_admin) roles.push('ADMIN')
    return { ...raw, roles }
  }

  // In-flight promise guard: if two navigations fire before the first init() resolves,
  // both await the same promise instead of issuing duplicate /auth/me requests.
  let initPromise: Promise<void> | null = null

  interface MeResponse {
  user: User
}

  /** Called once on app boot to restore session from the server cookie. */
  function init(): Promise<void> {
    if (initPromise !== null) return initPromise

    initPromise = (async () => {
      try {
        initError.value = null
        const res = await api.get<MeResponse>('/auth/me')
        user.value = normalizeUser(res.user)
      } catch (e) {
        user.value = null
        // 401 means the user is simply not logged in — expected, not an error.
        // Any other failure (network down, 5xx) is surfaced so the UI can show a retry.
        const isUnauthenticated = e instanceof ApiError && e.status === 401
        if (!isUnauthenticated) {
          initError.value = e instanceof Error ? e : new Error(String(e))
        }
      } finally {
        initialized.value = true
        // Clear the promise for non-auth errors so the next navigation can retry.
        if (initError.value !== null) {
          initPromise = null
        }
      }
    })()

    return initPromise
  }

  async function login(email: string, password: string): Promise<void> {
    user.value = normalizeUser(await api.post<User>('/auth/login', { email, password }))
  }

  async function register(email: string, password: string): Promise<void> {
    user.value = normalizeUser(await api.post<User>('/auth/register', { email, password }))
  }

  async function logout(): Promise<void> {
    // Clear state optimistically so the router guard redirects to /login even if
    // the API call fails (e.g. network error). The server session may still be live
    // in that case, but the user is safely locked out of the UI.
    user.value = null
    initPromise = null
    initialized.value = false
    await api.post('/auth/logout').catch(() => {})
  }

  async function changePassword(current: string, next: string): Promise<void> {
    await api.patch('/auth/password', { current_password: current, new_password: next })
  }

  async function updateAccount(username: string): Promise<void> {
    const updated = await api.patch<{ user: User }>('/auth/account', { username })
    user.value = normalizeUser(updated.user)
  }

  return { user, initialized, initError, init, login, register, logout, changePassword, updateAccount }
})
