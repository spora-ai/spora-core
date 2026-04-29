import { setActivePinia, createPinia } from 'pinia'
import { useMailConfigStore } from '@/stores/mailConfig'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(
      public readonly code: string,
      message: string,
      public readonly status: number,
    ) {
      super(message)
    }
  },
}))

import { api, ApiError } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

const mockMailConfig = {
  driver: 'smtp',
  host: 'smtp.example.com',
  port: 587,
  username: 'noreply@example.com',
  password: '***',
  from_address: 'noreply@example.com',
  from_name: 'Spora',
  encryption: 'tls',
}

describe('useMailConfigStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchConfig', () => {
    it('fetches mail config and sets config state', async () => {
      mockApi.get.mockResolvedValueOnce({ mail_config: mockMailConfig })

      const store = useMailConfigStore()
      await store.fetchConfig()

      expect(store.config).toEqual(mockMailConfig)
      expect(store.loading).toBe(false)
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('UNAUTHORIZED', 'Admin access required', 403))

      const store = useMailConfigStore()
      await expect(store.fetchConfig()).rejects.toThrow(ApiError)
      expect(store.error).toBe('Admin access required')
      expect(store.loading).toBe(false)
    })
  })

  describe('saveConfig', () => {
    it('patches mail-config and updates config state', async () => {
      const updated = { ...mockMailConfig, from_name: 'My App' }
      mockApi.patch.mockResolvedValueOnce({ mail_config: updated })

      const store = useMailConfigStore()
      const result = await store.saveConfig({ from_name: 'My App' })

      expect(mockApi.patch).toHaveBeenCalledWith('/mail-config', { from_name: 'My App' })
      expect(store.config?.from_name).toBe('My App')
      expect(result.from_name).toBe('My App')
      expect(store.saving).toBe(false)
    })

    it('sets error and rethrows on failure', async () => {
      mockApi.patch.mockRejectedValueOnce(new ApiError('VALIDATION_ERROR', 'Invalid driver', 422))

      const store = useMailConfigStore()
      await expect(store.saveConfig({ driver: 'invalid' })).rejects.toThrow(ApiError)
      expect(store.error).toBe('Invalid driver')
      expect(store.saving).toBe(false)
    })
  })

  describe('testConnection', () => {
    it('posts to test endpoint and returns success message', async () => {
      mockApi.post.mockResolvedValueOnce({ message: 'Test email sent successfully.' })

      const store = useMailConfigStore()
      const result = await store.testConnection()

      expect(mockApi.post).toHaveBeenCalledWith('/mail-config/test')
      expect(result).toBe('Test email sent successfully.')
      expect(store.testing).toBe(false)
    })

    it('sets error and rethrows when test fails', async () => {
      mockApi.post.mockRejectedValueOnce(new ApiError('MAIL_SEND_FAILED', 'SMTP connection refused', 500))

      const store = useMailConfigStore()
      await expect(store.testConnection()).rejects.toThrow(ApiError)
      expect(store.error).toBe('SMTP connection refused')
      expect(store.testing).toBe(false)
    })
  })
})
