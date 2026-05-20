import { setActivePinia, createPinia } from 'pinia'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
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

const mockDriver = {
  name: 'openai_compatible',
  display_name: 'OpenAI Compatible',
  driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
    { key: 'model', label: 'Model', type: 'text', description: '', default: 'gpt-4o', required: false, scope: 'global', options: null },
    { key: 'base_url', label: 'Base URL', type: 'text', description: '', default: 'https://api.openai.com/v1', required: false, scope: 'global', options: null },
  ],
}

const mockConfig = {
  id: 1,
  name: 'My Config',
  driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
  driver_name: 'openai_compatible',
  driver_display_name: 'OpenAI Compatible',
  settings: { api_key: '***', model: 'gpt-4o', base_url: 'https://api.openai.com/v1' },
  is_default: false,
  is_global: false,
  user_id: 1,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:01Z',
}

describe('useLlmConfigsStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('loadDrivers', () => {
    it('fetches drivers and stores them', async () => {
      mockApi.get.mockResolvedValueOnce({ drivers: [mockDriver] })

      const store = useLlmConfigsStore()
      await store.loadDrivers()

      expect(store.drivers).toEqual([mockDriver])
      expect(store.loadingDrivers).toBe(false)
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('UNKNOWN', 'Server error', 500))

      const store = useLlmConfigsStore()
      await store.loadDrivers()

      expect(store.error).toBe('Server error')
      expect(store.loadingDrivers).toBe(false)
    })
  })

  describe('loadConfigs', () => {
    it('fetches configs and stores them', async () => {
      mockApi.get.mockResolvedValueOnce({ configs: [mockConfig] })

      const store = useLlmConfigsStore()
      await store.loadConfigs()

      expect(store.configs).toEqual([mockConfig])
      expect(store.loadingConfigs).toBe(false)
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('UNKNOWN', 'Unauthorized', 401))

      const store = useLlmConfigsStore()
      await store.loadConfigs()

      expect(store.error).toBe('Unauthorized')
      expect(store.loadingConfigs).toBe(false)
    })
  })

  describe('createConfig', () => {
    it('posts to /llm-configs and appends to configs list', async () => {
      mockApi.post.mockResolvedValueOnce({ config: mockConfig })

      const store = useLlmConfigsStore()
      const result = await store.createConfig({
        name: 'My Config',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        settings: { api_key: 'sk-xxx', model: 'gpt-4o', base_url: 'https://api.openai.com/v1' },
      })

      expect(mockApi.post).toHaveBeenCalledWith('/llm-configs', {
        name: 'My Config',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        settings: { api_key: 'sk-xxx', model: 'gpt-4o', base_url: 'https://api.openai.com/v1' },
      })
      expect(store.configs).toContainEqual(mockConfig)
      expect(result).toEqual(mockConfig)
    })

    it('sets error and rethrows on failure', async () => {
      mockApi.post.mockRejectedValueOnce(new ApiError('VALIDATION_ERROR', 'Name is required', 422))

      const store = useLlmConfigsStore()
      await expect(store.createConfig({
        name: '',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        settings: {},
      })).rejects.toThrow(ApiError)

      expect(store.error).toBe('Name is required')
    })
  })

  describe('updateConfig', () => {
    it('puts to /llm-configs/{id} and replaces in configs list', async () => {
      const updated = { ...mockConfig, name: 'Updated' }
      mockApi.put.mockResolvedValueOnce({ config: updated })

      const store = useLlmConfigsStore()
      store.configs = [mockConfig]

      const result = await store.updateConfig(1, { name: 'Updated' })

      expect(mockApi.put).toHaveBeenCalledWith('/llm-configs/1', { name: 'Updated' })
      expect(store.configs[0].name).toBe('Updated')
      expect(result.name).toBe('Updated')
    })
  })

  describe('deleteConfig', () => {
    it('deletes from API and removes from configs list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useLlmConfigsStore()
      store.configs = [mockConfig]

      await store.deleteConfig(1)

      expect(mockApi.delete).toHaveBeenCalledWith('/llm-configs/1')
      expect(store.configs).toHaveLength(0)
    })
  })

  describe('ensure', () => {
    it('loads drivers and configs on first call', async () => {
      mockApi.get
        .mockResolvedValueOnce({ drivers: [mockDriver] })
        .mockResolvedValueOnce({ configs: [mockConfig] })

      const store = useLlmConfigsStore()
      await store.ensure()

      expect(store.drivers).toEqual([mockDriver])
      expect(store.configs).toEqual([mockConfig])
      expect(store.initialized).toBe(true)
    })

    it('does not call the API a second time when already initialized', async () => {
      mockApi.get
        .mockResolvedValueOnce({ drivers: [mockDriver] })
        .mockResolvedValueOnce({ configs: [mockConfig] })
        .mockResolvedValueOnce({ config: null })

      const store = useLlmConfigsStore()
      await store.ensure()
      await store.ensure() // second call — must be no-op

      expect(mockApi.get).toHaveBeenCalledTimes(3) // one for drivers, one for configs, one for global default
    })

    it('sets initialized=true before awaiting so parallel callers skip', async () => {
      mockApi.get
        .mockResolvedValueOnce({ drivers: [mockDriver] })
        .mockResolvedValueOnce({ configs: [mockConfig] })
        .mockResolvedValueOnce({ config: null })

      const store = useLlmConfigsStore()
      // Trigger two concurrent calls
      const [, ] = await Promise.all([store.ensure(), store.ensure()])

      expect(mockApi.get).toHaveBeenCalledTimes(3) // not 6
    })

    it('remains initialized even if loading fails (error stored, no retry loop)', async () => {
      mockApi.get.mockRejectedValue(new ApiError('SERVER_ERROR', 'Oops', 500))

      const store = useLlmConfigsStore()
      await store.ensure()

      expect(store.initialized).toBe(true)
      expect(store.error).toBeTruthy()
    })
  })

  describe('driverForClass', () => {
    it('returns the driver with matching driver_class', () => {
      const store = useLlmConfigsStore()
      store.drivers = [mockDriver]

      const found = store.driverForClass('Spora\\Drivers\\OpenAICompatibleDriver')
      expect(found).toEqual(mockDriver)
    })

    it('returns undefined for unknown class', () => {
      const store = useLlmConfigsStore()
      store.drivers = []

      const found = store.driverForClass('Unknown\\Class')
      expect(found).toBeUndefined()
    })
  })

  describe('driverByName', () => {
    it('returns the driver with matching name', () => {
      const store = useLlmConfigsStore()
      store.drivers = [mockDriver]

      const found = store.driverByName('openai_compatible')
      expect(found).toEqual(mockDriver)
    })
  })
})
