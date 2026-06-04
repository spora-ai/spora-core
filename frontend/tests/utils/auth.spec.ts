import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fetchConfig, isRegistrationEnabled, clearConfigCache } from '@/utils/auth'

// Create mock fetch using vi.fn()
const mockFetch = vi.fn()
globalThis.fetch = mockFetch

describe('auth utils', () => {
  beforeEach(() => {
    mockFetch.mockClear()
    clearConfigCache()
  })

  describe('fetchConfig', () => {
    it('returns allow_registration true when config endpoint returns true', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ allow_registration: true }),
      })

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(true)
      expect(mockFetch).toHaveBeenCalledWith('/api/v1/config')
    })

    it('returns allow_registration false when config endpoint returns false', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ allow_registration: false }),
      })

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(false)
    })

    it('fails open (allow_registration true) when fetch fails', async () => {
      mockFetch.mockImplementation(() => Promise.reject(new Error('network error')))

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(true)
    })

    it('fails open when response is not ok', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: false,
      })

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(true)
    })
  })

  describe('isRegistrationEnabled', () => {
    it('returns true when config allows registration', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ allow_registration: true }),
      })

      const enabled = await isRegistrationEnabled()

      expect(enabled).toBe(true)
    })

    it('returns false when config disallows registration', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ allow_registration: false }),
      })

      const enabled = await isRegistrationEnabled()

      expect(enabled).toBe(false)
    })
  })
})
