import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    put: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(
      message: string,
      public readonly code: string,
      public readonly status: number,
    ) {
      super(message)
      this.name = 'ApiError'
    }
  },
}))

import { api, ApiError } from '@/api/client'
import { useLlmPreferencesStore } from '@/stores/llmPreferencesStore'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  put: ReturnType<typeof vi.fn>
}

const sampleConfig = {
  id: 1,
  name: 'My Config',
  driver_class: 'X',
  driver_name: 'openai',
  driver_display_name: 'OpenAI',
  settings: {},
  is_default: false,
  is_global: false,
  user_id: 1,
  created_at: '',
  updated_at: '',
}

describe('useLlmPreferencesStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('loadPreference', () => {
    it('loads preference from /user-preferences/llm', async () => {
      mockApi.get.mockResolvedValueOnce({ config: sampleConfig })

      const store = useLlmPreferencesStore()
      await store.loadPreference()

      expect(mockApi.get).toHaveBeenCalledWith('/user-preferences/llm')
      expect(store.preference).toEqual({ config: sampleConfig })
      expect(store.loading).toBe(false)
      expect(store.error).toBeNull()
    })

    it('stores null preference when API returns null config', async () => {
      mockApi.get.mockResolvedValueOnce({ config: null })

      const store = useLlmPreferencesStore()
      await store.loadPreference()

      expect(store.preference).toBeNull()
    })

    it('sets error string from ApiError', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('No access', 'FORBIDDEN', 403))

      const store = useLlmPreferencesStore()
      await store.loadPreference()

      expect(store.error).toBe('No access')
      expect(store.loading).toBe(false)
    })

    it('sets default error string for non-ApiError', async () => {
      mockApi.get.mockRejectedValueOnce(new Error('network'))

      const store = useLlmPreferencesStore()
      await store.loadPreference()

      expect(store.error).toBe('Failed to load LLM preference.')
    })
  })

  describe('setPreference', () => {
    it('puts new preference id and stores returned config', async () => {
      mockApi.put.mockResolvedValueOnce({ config: sampleConfig })

      const store = useLlmPreferencesStore()
      await store.setPreference(1)

      expect(mockApi.put).toHaveBeenCalledWith('/user-preferences/llm', { config_id: 1 })
      expect(store.preference).toEqual({ config: sampleConfig })
      expect(store.error).toBeNull()
    })

    it('handles null config response', async () => {
      mockApi.put.mockResolvedValueOnce({ config: null })

      const store = useLlmPreferencesStore()
      await store.setPreference(null)

      expect(store.preference).toBeNull()
    })

    it('sets error and rethrows on ApiError', async () => {
      mockApi.put.mockRejectedValueOnce(new ApiError('Bad request', 'VALIDATION', 422))

      const store = useLlmPreferencesStore()
      await expect(store.setPreference(99)).rejects.toThrow()
      expect(store.error).toBe('Bad request')
    })

    it('sets default error and rethrows on non-ApiError', async () => {
      mockApi.put.mockRejectedValueOnce(new Error('network down'))

      const store = useLlmPreferencesStore()
      await expect(store.setPreference(1)).rejects.toThrow()
      expect(store.error).toBe('Failed to set LLM preference.')
    })
  })

  describe('clearPreference', () => {
    it('calls setPreference(null)', async () => {
      mockApi.put.mockResolvedValueOnce({ config: null })

      const store = useLlmPreferencesStore()
      await store.clearPreference()

      expect(mockApi.put).toHaveBeenCalledWith('/user-preferences/llm', { config_id: null })
      expect(store.preference).toBeNull()
    })
  })
})
