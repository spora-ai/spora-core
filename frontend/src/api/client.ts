// CSRF strategy: session cookies are scoped SameSite=Lax by PHP's default session config,
// which prevents cross-origin form/navigation requests from carrying the session cookie.
// A separate XSRF-TOKEN double-submit pattern is not implemented.

const BASE_URL = import.meta.env.VITE_API_URL ?? ''

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(init.headers ? Object.fromEntries(new Headers(init.headers)) : {}),
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
    throw new ApiError(code, message, response.status)
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
