/**
 * HTTP status → { severity, code?, action? } mapper.
 *
 * Used by the API client and global error handler to determine
 * how to display an error based on its HTTP status code.
 */

export type Severity = 'error' | 'warning' | 'success' | 'info'

export interface ErrorMapping {
  severity: Severity
  action?: string
}

export function mapHttpStatus(status: number, _errorCode?: string): ErrorMapping {
  // 2xx success — not an error, shouldn't be called
  if (status >= 200 && status < 300) {
    return { severity: 'success' }
  }

  // 401 → error + redirect to login
  if (status === 401) {
    return { severity: 'error', action: 'login' }
  }

  // 403 → error
  if (status === 403) {
    return { severity: 'error' }
  }

  // 404 → warning
  if (status === 404) {
    return { severity: 'warning' }
  }

  // 429 rate limit → warning with retry hint
  if (status === 429) {
    return { severity: 'warning', action: 'retry' }
  }

  // 500+ → error
  if (status >= 500) {
    return { severity: 'error' }
  }

  // 400–499 (other than above) → warning
  return { severity: 'warning' }
}

/**
 * Maps API error code to severity and recommended action.
 * This is used when the server returns structured error JSON.
 */
export function mapErrorCode(code: string): ErrorMapping {
  switch (code) {
    case 'UNAUTHENTICATED':
    case 'ACCOUNT_UNVERIFIED':
    case 'ACCOUNT_SUSPENDED':
    case 'FORBIDDEN':
      return { severity: 'error', action: code === 'UNAUTHENTICATED' ? 'login' : undefined }

    case 'RATE_LIMIT':
    case 'TOO_MANY_REQUESTS':
      return { severity: 'warning', action: 'retry' }

    case 'LLM_RATE_LIMIT':
    case 'LLM_PROVIDER_ERROR':
      return { severity: 'warning', action: 'retry' }

    case 'DECRYPTION_FAILED':
      return { severity: 'error', action: 'contact' }

    case 'NOT_FOUND':
    case 'INVALID_STATE':
      return { severity: 'warning' }

    default:
      return { severity: 'warning' }
  }
}