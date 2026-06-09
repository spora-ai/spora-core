import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fetchConfig, isRegistrationEnabled, clearConfigCache } from '@/utils/auth'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
  },
}))

import { api } from '@/api/client'

const getMock = api.get as ReturnType<typeof vi.fn>

describe('auth utils', () => {
  beforeEach(() => {
    getMock.mockReset()
    clearConfigCache()
  })

  describe('fetchConfig', () => {
    it('returns allow_registration true when config endpoint returns true', async () => {
      getMock.mockResolvedValueOnce({ allow_registration: true })

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(true)
      expect(getMock).toHaveBeenCalledWith('/config')
    })

    it('returns allow_registration false when config endpoint returns false', async () => {
      getMock.mockResolvedValueOnce({ allow_registration: false })

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(false)
    })

    it('fails open (allow_registration true) when the API call throws', async () => {
      getMock.mockRejectedValueOnce(new Error('network error'))

      const config = await fetchConfig()

      expect(config.allow_registration).toBe(true)
    })
  })

  describe('isRegistrationEnabled', () => {
    it('returns true when config allows registration', async () => {
      getMock.mockResolvedValueOnce({ allow_registration: true })

      const enabled = await isRegistrationEnabled()

      expect(enabled).toBe(true)
    })

    it('returns false when config disallows registration', async () => {
      getMock.mockResolvedValueOnce({ allow_registration: false })

      const enabled = await isRegistrationEnabled()

      expect(enabled).toBe(false)
    })
  })
})
