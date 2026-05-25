import type { ApiConfig } from '@/types/auth'

let cachedConfig: ApiConfig | null = null

/**
 * Clear the cached config. Exported for testing only.
 */
export function clearConfigCache(): void {
  cachedConfig = null
}

/**
 * Fetch public app config including whether registration is allowed.
 * Result is cached for the lifetime of the page session.
 */
export async function fetchConfig(): Promise<ApiConfig> {
  if (cachedConfig !== null) {
    return cachedConfig
  }
  try {
    const res = await fetch(`/api/v1/config`)
    if (!res.ok) {
      // Fail open: if the config endpoint is unreachable, assume registration is allowed
      cachedConfig = { allow_registration: true }
      return cachedConfig
    }
    const json = await res.json()
    cachedConfig = json as ApiConfig
    return cachedConfig
  } catch {
    // Fail open: if the config endpoint is unreachable, assume registration is allowed
    cachedConfig = { allow_registration: true }
    return cachedConfig
  }
}

/**
 * Check whether registration is enabled.
 * Uses cached config if available, otherwise fetches it.
 */
export async function isRegistrationEnabled(): Promise<boolean> {
  const config = await fetchConfig()
  return config.allow_registration
}

/**
 * Get the redirect target for an unauthenticated user trying to access a guest-only route.
 */
export function getGuestRedirect(authenticated: boolean): string | null {
  return authenticated ? '/dashboard' : '/login'
}
