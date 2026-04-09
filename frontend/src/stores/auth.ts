import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'

export interface User {
  id: number
  email: string
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const initialized = ref(false)
  const initError = ref<Error | null>(null)

  // In-flight promise guard: if two navigations fire before the first init() resolves,
  // both await the same promise instead of issuing duplicate /auth/me requests.
  let initPromise: Promise<void> | null = null

  /** Called once on app boot to restore session from the server cookie. */
  function init(): Promise<void> {
    if (initPromise !== null) return initPromise

    initPromise = (async () => {
      try {
        initError.value = null
        user.value = await api.get<User>('/auth/me')
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
    user.value = await api.post<User>('/auth/login', { email, password })
  }

  async function register(email: string, password: string): Promise<void> {
    user.value = await api.post<User>('/auth/register', { email, password })
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

  return { user, initialized, initError, init, login, register, logout }
})
