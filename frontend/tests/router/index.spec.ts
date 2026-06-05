/**
 * router/index — verifies the route table shape and the guards' redirect logic.
 *
 * We don't navigate end-to-end because that would lazy-load every page chunk
 * during a test run; instead we mock `vue-router` to capture the route table
 * + guard function, then call the guard directly with synthetic `to` and
 * `auth.user` states.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const beforeEachSpy = vi.fn()
let capturedRoutes: unknown[] = []

vi.mock('vue-router', () => ({
  createRouter: (opts: { routes: unknown[] }) => {
    capturedRoutes = opts.routes
    return { beforeEach: beforeEachSpy }
  },
  createWebHistory: vi.fn(() => ({})),
}))

const authInit = vi.fn().mockResolvedValue(undefined)
const authState = {
  initialized: true,
  user: null as { id: number } | null,
  init: authInit,
}

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))

const isRegistrationEnabledMock = vi.fn().mockResolvedValue(true)
vi.mock('@/utils/auth', () => ({
  isRegistrationEnabled: () => isRegistrationEnabledMock(),
}))

describe('router/index', () => {
  beforeEach(async () => {
    authInit.mockClear()
    isRegistrationEnabledMock.mockReset().mockResolvedValue(true)
    authState.initialized = true
    authState.user = null
    beforeEachSpy.mockReset()
    // Re-import so the router module is freshly evaluated for each test
    vi.resetModules()
    await import('@/router/index')
  })

  it('registers a non-empty route table with named routes', () => {
    expect(capturedRoutes.length).toBeGreaterThan(0)
    const names = capturedRoutes
      .map((r) => (r as { name?: string }).name)
      .filter(Boolean)
    expect(names).toContain('login')
    expect(names).toContain('dashboard')
    expect(names).toContain('agent')
    expect(names).toContain('task')
  })

  it('configures auth-guarded routes', () => {
    const dashboard = capturedRoutes.find(
      (r) => (r as { name?: string }).name === 'dashboard',
    ) as { meta: { requiresAuth: boolean } }
    expect(dashboard.meta.requiresAuth).toBe(true)
  })

  it('configures guest-only routes', () => {
    const login = capturedRoutes.find(
      (r) => (r as { name?: string }).name === 'login',
    ) as { meta: { requiresGuest: boolean } }
    expect(login.meta.requiresGuest).toBe(true)
  })

  it('exposes a wildcard route redirecting to /', () => {
    const wildcard = capturedRoutes.find(
      (r) => (r as { path?: string }).path === '/:pathMatch(.*)*',
    ) as { redirect?: string }
    expect(wildcard.redirect).toBe('/')
  })

  it('register beforeEnter redirects to login when registration is disabled', async () => {
    isRegistrationEnabledMock.mockResolvedValueOnce(false)
    const register = capturedRoutes.find(
      (r) => (r as { name?: string }).name === 'register',
    ) as { beforeEnter: () => Promise<unknown> }
    const result = await register.beforeEnter()
    expect(result).toEqual({ name: 'login' })
  })

  it('register beforeEnter passes through when registration is enabled', async () => {
    isRegistrationEnabledMock.mockResolvedValueOnce(true)
    const register = capturedRoutes.find(
      (r) => (r as { name?: string }).name === 'register',
    ) as { beforeEnter: () => Promise<unknown> }
    const result = await register.beforeEnter()
    expect(result).toBeUndefined()
  })

  describe('global beforeEach guard', () => {
    function getGuard() {
      // beforeEach() was invoked once during module init
      return beforeEachSpy.mock.calls[0][0] as (
        to: { meta: Record<string, unknown> },
      ) => Promise<unknown>
    }

    it('calls auth.init() when not initialized', async () => {
      authState.initialized = false
      const guard = getGuard()
      await guard({ meta: {} })
      expect(authInit).toHaveBeenCalledTimes(1)
    })

    it('redirects unauthenticated users from auth-required routes', async () => {
      authState.user = null
      const guard = getGuard()
      const result = await guard({ meta: { requiresAuth: true } })
      expect(result).toEqual({ name: 'login' })
    })

    it('allows authenticated users through auth-required routes', async () => {
      authState.user = { id: 1 }
      const guard = getGuard()
      const result = await guard({ meta: { requiresAuth: true } })
      expect(result).toBeUndefined()
    })

    it('redirects authenticated users away from guest-only routes', async () => {
      authState.user = { id: 1 }
      const guard = getGuard()
      const result = await guard({ meta: { requiresGuest: true } })
      expect(result).toEqual({ name: 'dashboard' })
    })

    it('allows unauthenticated users to access guest-only routes', async () => {
      authState.user = null
      const guard = getGuard()
      const result = await guard({ meta: { requiresGuest: true } })
      expect(result).toBeUndefined()
    })

    it('allows everyone through routes with no meta guards', async () => {
      authState.user = null
      const guard = getGuard()
      const result = await guard({ meta: {} })
      expect(result).toBeUndefined()
    })
  })
})
