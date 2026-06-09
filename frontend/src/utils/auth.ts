import { api } from '@/api/client'
import { log } from '@/utils/logger'
import type { ApiConfig } from '@/types/auth'

/**
 * Authentication utilities for config fetching and route guards.
 */
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
 *
 * Goes through the shared `api` client (rather than a raw `fetch`) so the
 * call is interceptable by the `@/api/client` mock in tests, and so it
 * benefits from the same base-URL / header handling as the rest of the
 * app.
 */
export async function fetchConfig(): Promise<ApiConfig> {
  if (cachedConfig !== null) {
    return cachedConfig
  }
  try {
    cachedConfig = await api.get<ApiConfig>('/config')
    return cachedConfig
  } catch (e) {
    // Fail open: if the config endpoint is unreachable, assume registration is allowed
    log.warn('[auth] /config unreachable; falling back to allow_registration=true', e)
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
 * Get the redirect target for a user trying to access a guest-only route.
 * Redirects authenticated users to the dashboard. Allows unauthenticated users (guests) to stay.
 */
export function getGuestRedirect(authenticated: boolean): string | null {
  return authenticated ? '/dashboard' : null
}
