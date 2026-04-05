import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useToolSettings } from '@/composables/useToolSettings'

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
      this.name = 'ApiError'
    }
  },
}))

import { api } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

describe('useToolSettings', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  describe('global mode (no agentId)', () => {
    const { getSettings, putSettings } = useToolSettings()

    describe('getSettings', () => {
      it('calls GET /tools/{toolId}/settings', async () => {
        const settings = { 'core.openai.api_key': 'sk-123' }
        mockApi.get.mockResolvedValueOnce({ settings })

        const result = await getSettings('llm_configuration')

        expect(mockApi.get).toHaveBeenCalledWith('/tools/llm_configuration/settings')
        expect(result).toEqual(settings)
      })

      it('returns empty object on 404', async () => {
        const { ApiError } = await import('@/api/client')
        const err = new ApiError('NOT_FOUND', 'Not found', 404)
        mockApi.get.mockRejectedValueOnce(err)

        const result = await getSettings('llm_configuration')

        expect(result).toEqual({})
      })

      it('re-throws non-404 errors', async () => {
        const { ApiError } = await import('@/api/client')
        const err = new ApiError('SERVER_ERROR', 'Server error', 500)
        mockApi.get.mockRejectedValueOnce(err)

        await expect(getSettings('llm_configuration')).rejects.toThrow('Server error')
      })
    })

    describe('putSettings', () => {
      it('omits unchanged password fields (serverValue=***, same value)', async () => {
        const serverSettings = { 'core.openai.api_key': '***' }
        const callerSettings = { 'core.openai.api_key': '' } // user cleared it → send ''

        mockApi.put.mockResolvedValueOnce({ settings: { 'core.openai.api_key': '' } })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        expect(mockApi.put).toHaveBeenCalledWith(
          '/tools/llm_configuration/settings',
          { settings: { 'core.openai.api_key': '' } },
        )
      })

      it('omits unchanged non-password fields (no diff)', async () => {
        const serverSettings = { 'core.openai.api_key': 'sk-existing' }
        const callerSettings = { 'core.openai.api_key': 'sk-existing' }

        mockApi.put.mockResolvedValueOnce({ settings: serverSettings })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        expect(mockApi.put).toHaveBeenCalledWith(
          '/tools/llm_configuration/settings',
          { settings: {} },
        )
      })

      it('sends changed non-password fields', async () => {
        const serverSettings = { 'core.openai.api_key': 'sk-old' }
        const callerSettings = { 'core.openai.api_key': 'sk-new' }

        mockApi.put.mockResolvedValueOnce({ settings: { 'core.openai.api_key': 'sk-new' } })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        expect(mockApi.put).toHaveBeenCalledWith(
          '/tools/llm_configuration/settings',
          { settings: { 'core.openai.api_key': 'sk-new' } },
        )
      })

      it('sends new keys not in serverSettings', async () => {
        const serverSettings = {}
        const callerSettings = { 'core.openai.api_key': 'sk-new' }

        mockApi.put.mockResolvedValueOnce({ settings: { 'core.openai.api_key': 'sk-new' } })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        expect(mockApi.put).toHaveBeenCalledWith(
          '/tools/llm_configuration/settings',
          { settings: { 'core.openai.api_key': 'sk-new' } },
        )
      })

      it('omits password field when user leaves *** unchanged (empty string from masked)', async () => {
        const serverSettings = { 'core.openai.api_key': '***' }
        const callerSettings = { 'core.openai.api_key': '' } // was masked, user left it empty

        mockApi.put.mockResolvedValueOnce({ settings: {} })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        // Empty string from masked → user cleared it → send ''
        expect(mockApi.put).toHaveBeenCalledWith(
          '/tools/llm_configuration/settings',
          { settings: { 'core.openai.api_key': '' } },
        )
      })
    })
  })

  describe('per-agent mode (agentId provided)', () => {
    const { getSettings, putSettings } = useToolSettings(42)

    describe('getSettings', () => {
      it('calls GET /agents/{id}/tools/{toolId}/override', async () => {
        const settings = { 'core.openai.api_key': 'sk-agent' }
        mockApi.get.mockResolvedValueOnce({ settings })

        const result = await getSettings('llm_configuration')

        expect(mockApi.get).toHaveBeenCalledWith('/agents/42/tools/llm_configuration/override')
        expect(result).toEqual(settings)
      })
    })

    describe('putSettings', () => {
      it('calls PUT /agents/{id}/tools/{toolId}/override', async () => {
        const serverSettings = { 'core.openai.api_key': '***' }
        const callerSettings = { 'core.openai.api_key': 'sk-updated' }

        mockApi.put.mockResolvedValueOnce({ settings: { 'core.openai.api_key': 'sk-updated' } })

        await putSettings('llm_configuration', callerSettings, serverSettings)

        expect(mockApi.put).toHaveBeenCalledWith(
          '/agents/42/tools/llm_configuration/override',
          { settings: { 'core.openai.api_key': 'sk-updated' } },
        )
      })
    })
  })

  describe('URL encoding', () => {
    it('encodes toolId with special characters', async () => {
      const { getSettings } = useToolSettings()
      mockApi.get.mockResolvedValueOnce({ settings: {} })

      await getSettings('tavily-search')

      expect(mockApi.get).toHaveBeenCalledWith('/tools/tavily-search/settings')
    })

    it('encodes agent-scoped toolId with special characters', async () => {
      const { getSettings } = useToolSettings(1)
      mockApi.get.mockResolvedValueOnce({ settings: {} })

      await getSettings('tavily-search')

      expect(mockApi.get).toHaveBeenCalledWith('/agents/1/tools/tavily-search/override')
    })
  })
})
