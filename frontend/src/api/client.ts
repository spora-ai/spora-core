// CSRF strategy: session cookies are scoped SameSite=Lax by PHP's default session config.
// A CSRF token (X-CSRF-Token header) is required on all state-changing requests (POST/PUT/PATCH/DELETE).
// The token is obtained from the auth store after login/register/me and sent as a header.

const BASE_URL = import.meta.env.VITE_API_URL ?? ''

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly code: string,
    public readonly status: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type SessionExpiredHandler = () => void
let _sessionExpiredHandler: SessionExpiredHandler | null = null

export function setupSessionHandler(handler: SessionExpiredHandler): void {
  _sessionExpiredHandler = handler
}

// State-changing HTTP methods that require a CSRF token
const STATE_CHANGING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function unwrap(val: unknown): unknown {
  return val && typeof val === 'object' && 'value' in val ? val.value : val
}

async function injectCsrfIfNeeded(method: string, headers: Record<string, string>): Promise<void> {
  if (!STATE_CHANGING_METHODS.has(method)) {
    return
  }
  const authStore = await import('@/stores/auth')
  const auth = authStore.useAuthStore()
  const csrfVal = unwrap(auth.csrfToken) as string | null
  if (csrfVal) {
    headers['X-CSRF-Token'] = csrfVal
    return
  }
  if (!unwrap(auth.user)) {
    return
  }
  // Token missing but user appears logged in — fetch a fresh one from /auth/me
  const meRes = await api.get<{ csrf_token?: string }>('/auth/me')
  if (meRes.csrf_token) {
    auth.$patch({ csrfToken: meRes.csrf_token })
    headers['X-CSRF-Token'] = meRes.csrf_token
  }
}

async function notifySessionExpired(): Promise<void> {
  const auth = await import('@/stores/auth').then(m => m.useAuthStore())
  if (unwrap(auth.initialized) && unwrap(auth.user)) {
    _sessionExpiredHandler?.()
  }
}

function buildError(body: Record<string, unknown> | null, status: number): ApiError {
  const err = body?.error as Record<string, string> | undefined
  const code = err?.code ?? 'UNKNOWN_ERROR'
  const message = err?.message ?? `HTTP ${status}`
  return new ApiError(message, code, status)
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(init.headers ? Object.fromEntries(new Headers(init.headers)) : {}),
  }

  // Inject CSRF token from auth store for state-changing requests
  const method = (init.method ?? 'GET').toUpperCase()
  await injectCsrfIfNeeded(method, headers)

  const response = await fetch(`${BASE_URL}/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers,
  })

  // Parse JSON once; treat an empty body (204, unexpected HTML) as null.
  const text = await response.text()
  const body = text.length > 0 ? (JSON.parse(text) as Record<string, unknown>) : null

  if (!response.ok) {
    if (response.status === 401) {
      const err = body?.error as Record<string, string> | undefined
      if (err?.code === 'UNAUTHENTICATED') {
        await notifySessionExpired()
      }
    }
    throw buildError(body, response.status)
  }

  // body.data is the standard envelope; fall back to the whole body for bare responses.
  return ((body === null ? undefined : (body.data ?? body)) as T)
}

export const api = {
  get: <T>(path: string) =>
    request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body === undefined ? undefined : JSON.stringify(body) }),
  patch: <T>(path: string, body: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(body) }),
  put: <T>(path: string, body: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(body) }),
  delete: <T>(path: string) =>
    request<T>(path, { method: 'DELETE' }),
}
