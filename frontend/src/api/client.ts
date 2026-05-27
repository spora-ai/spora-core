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
const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE']

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(init.headers ? Object.fromEntries(new Headers(init.headers)) : {}),
  }

  // Inject CSRF token from auth store for state-changing requests
  const method = (init.method ?? 'GET').toUpperCase()
  if (STATE_CHANGING_METHODS.includes(method)) {
    const auth = await import('@/stores/auth').then(m => m.useAuthStore())
    if (auth.csrfToken) {
      headers['X-CSRF-Token'] = auth.csrfToken
    }
  }

  const response = await fetch(`${BASE_URL}/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers,
  })

  // Parse JSON once; treat an empty body (204, unexpected HTML) as null.
  const text = await response.text()
  const body = text.length > 0 ? (JSON.parse(text) as Record<string, unknown>) : null

  if (!response.ok) {
    const err = body?.error as Record<string, string> | undefined
    const code = err?.code ?? 'UNKNOWN_ERROR'
    const message = err?.message ?? `HTTP ${response.status}`
    if (response.status === 401 && code === 'UNAUTHENTICATED') {
      const auth = await import('@/stores/auth').then(m => m.useAuthStore())
      if (auth.initialized && auth.user) {
        _sessionExpiredHandler?.()
      }
    }

    throw new ApiError(message, code, response.status)
  }

  // body.data is the standard envelope; fall back to the whole body for bare responses.
  return (body !== null ? ((body.data ?? body) as T) : (undefined as T))
}

export const api = {
  get: <T>(path: string) =>
    request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body !== undefined ? JSON.stringify(body) : undefined }),
  patch: <T>(path: string, body: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(body) }),
  put: <T>(path: string, body: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(body) }),
  delete: <T>(path: string) =>
    request<T>(path, { method: 'DELETE' }),
}
