import { setActivePinia, createPinia } from 'pinia'
import { useGlobalSettingsStore } from '@/stores/globalSettings'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    put: vi.fn(),
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

vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: vi.fn(() => ({
    getGlobalSettings: vi.fn().mockResolvedValue({}),
    putSettings: vi.fn().mockResolvedValue({}),
  })),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: vi.fn(() => ({
    initialized: true,
    init: vi.fn().mockResolvedValue(undefined),
  })),
}))

import { api, ApiError } from '@/api/client'
import { useToolSettings } from '@/composables/useToolSettings'
import { useAuthStore } from '@/stores/auth'

const mockApi = api as ReturnType<typeof vi.fn>
const mockToolSettings = useToolSettings as ReturnType<typeof vi.fn>

const mockDrivers = [
  {
    name: 'openai',
    display_name: 'OpenAI',
    driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
    settings_schema: [
      { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
    ],
  },
]

const mockTools = [
  {
    tool_class: 'Spora\\Tools\\TavilyTool',
    tool_name: 'tavily',
    display_name: 'Tavily',
    category: 'search',
    settings_schema: [
      { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
    ],
    operations: [],
  },
]

describe('useGlobalSettingsStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('loadDrivers', () => {
    it('fetches drivers from API and sets drivers state', async () => {
      mockApi.get.mockResolvedValueOnce({ drivers: mockDrivers })

      const store = useGlobalSettingsStore()
      await store.loadDrivers()

      expect(store.drivers).toEqual(mockDrivers)
      expect(store.loadingDrivers).toBe(false)
    })

    it('sets driverError on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('UNAUTHORIZED', 'Admin access required', 403))

      const store = useGlobalSettingsStore()
      await store.loadDrivers()

      expect(store.driverError).toBe('Admin access required')
      expect(store.drivers).toEqual([])
    })
  })

  describe('loadTools', () => {
    it('fetches tools from /tools endpoint', async () => {
      // The raw API response is { data: { tools: [...] } }
      // client.ts unwraps the 'data' envelope, so api.get() returns { tools: [...] }
      mockApi.get.mockResolvedValueOnce({ tools: mockTools })

      const store = useGlobalSettingsStore()
      await store.loadTools()

      expect(mockApi.get).toHaveBeenCalledWith('/tools')
      expect(store.allTools).toEqual(mockTools)
    })

    it('sets toolError on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('SERVER_ERROR', 'Server error', 500))

      const store = useGlobalSettingsStore()
      await store.loadTools()

      expect(store.toolError).toBe('Server error')
    })

    it('awaits auth.init() when auth is not yet initialized', async () => {
      const mockAuthStore = useAuthStore as ReturnType<typeof vi.fn>
      mockAuthStore.mockReturnValue({
        initialized: false,
        init: vi.fn().mockResolvedValue(undefined),
      })
      mockApi.get.mockResolvedValueOnce({ tools: mockTools })

      const store = useGlobalSettingsStore()
      await store.loadTools()

      expect(mockAuthStore().init).toHaveBeenCalled()
      expect(store.allTools).toEqual(mockTools)
    })

    it('does not call auth.init() when already initialized', async () => {
      const mockAuthStore = useAuthStore as ReturnType<typeof vi.fn>
      mockAuthStore.mockReturnValue({
        initialized: true,
        init: vi.fn(),
      })
      mockApi.get.mockResolvedValueOnce({ tools: mockTools })

      const store = useGlobalSettingsStore()
      await store.loadTools()

      expect(mockAuthStore().init).not.toHaveBeenCalled()
      expect(store.allTools).toEqual(mockTools)
    })
  })

  describe('loadAll', () => {
    it('loads both drivers and tools', async () => {
      mockApi.get
        .mockResolvedValueOnce({ drivers: mockDrivers })
        .mockResolvedValueOnce({ tools: mockTools })

      const store = useGlobalSettingsStore()
      await store.loadAll()

      expect(store.drivers).toEqual(mockDrivers)
      expect(store.allTools).toEqual(mockTools)
    })
  })

  describe('saveDriverSettings', () => {
    it('calls putSettings with driver name and updates driverSettings', async () => {
      const driver = mockDrivers[0]
      const savedSettings = { 'core.openai.api_key': 'sk-new' }
      mockToolSettings.mockReturnValue({
        getGlobalSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockResolvedValue(savedSettings),
      })

      const store = useGlobalSettingsStore()
      store.drivers = driver
      store.driverSettings[driver.name] = { 'core.openai.api_key': '' }

      await store.saveDriverSettings(driver as any)

      expect(mockToolSettings().putSettings).toHaveBeenCalled()
      expect(store.driverSettings[driver.name]).toEqual(savedSettings)
      expect(store.savingDriver).toBeNull()
    })

    it('sets driverError and rethrows on failure', async () => {
      const driver = mockDrivers[0]
      mockToolSettings.mockReturnValue({
        getGlobalSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockRejectedValue(new ApiError('SERVER_ERROR', 'Save failed', 500)),
      })

      const store = useGlobalSettingsStore()
      store.drivers = driver

      await expect(store.saveDriverSettings(driver as any)).rejects.toThrow()
      expect(store.driverError).toBe('Save failed')
    })
  })

  describe('saveToolSettings', () => {
    it('calls putSettings with tool name and updates toolSettings', async () => {
      const tool = mockTools[0]
      const savedSettings = { 'api_key': 'key-123' }
      mockToolSettings.mockReturnValue({
        getGlobalSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockResolvedValue(savedSettings),
      })

      const store = useGlobalSettingsStore()
      store.allTools = tool
      store.toolSettings[tool.tool_name] = {}

      await store.saveToolSettings(tool as any)

      expect(store.toolSettings[tool.tool_name]).toEqual(savedSettings)
      expect(store.savingTool).toBeNull()
    })

    it('sets toolError and rethrows on failure', async () => {
      const tool = mockTools[0]
      mockToolSettings.mockReturnValue({
        getGlobalSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockRejectedValue(new ApiError('VALIDATION_ERROR', 'Invalid', 422)),
      })

      const store = useGlobalSettingsStore()
      store.allTools = tool

      await expect(store.saveToolSettings(tool as any)).rejects.toThrow()
      expect(store.toolError).toBe('Invalid')
    })
  })
})
