import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '@/api/client'
import {
  enableTool,
  disableTool,
  getOperationOverride,
  getAllOperationOverrides,
  patchOperationOverride,
  getLLMConfig,
  putLLMConfig,
} from '@/composables/useAgentToolOverrides'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  patch: ReturnType<typeof vi.fn>
  put: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

beforeEach(() => {
  vi.resetAllMocks()
})

describe('useAgentToolOverrides', () => {
  describe('enableTool', () => {
    it('POSTs to /agents/{id}/tools/{name}/enable and returns the tool', async () => {
      const tool = { name: 'serper_search', enabled: true }
      mockApi.post.mockResolvedValueOnce({ tool })

      const result = await enableTool(1, 'serper_search')

      expect(mockApi.post).toHaveBeenCalledWith('/agents/1/tools/serper_search/enable')
      expect(result).toEqual(tool)
    })

    it('URL-encodes the tool name', async () => {
      mockApi.post.mockResolvedValueOnce({ tool: { name: 'llm/configuration' } })
      await enableTool(2, 'llm/configuration')
      expect(mockApi.post).toHaveBeenCalledWith('/agents/2/tools/llm%2Fconfiguration/enable')
    })
  })

  describe('disableTool', () => {
    it('DELETEs /agents/{id}/tools/{name}/enable', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)
      await disableTool(1, 'serper_search')
      expect(mockApi.delete).toHaveBeenCalledWith('/agents/1/tools/serper_search/enable')
    })
  })

  describe('getOperationOverride', () => {
    it('GETs the operation override endpoint', async () => {
      mockApi.get.mockResolvedValueOnce({
        enabled: true,
        default_requires_approval: false,
        effective_enabled: true,
        effective_requires_approval: false,
      })

      const result = await getOperationOverride(1, 'email', 'send')

      expect(mockApi.get).toHaveBeenCalledWith('/agents/1/tools/email/operations/send')
      expect(result).toMatchObject({ enabled: true, effective_enabled: true })
    })
  })

  describe('getAllOperationOverrides', () => {
    it('re-keys the flat operations array by tool_name, then operation', async () => {
      mockApi.get.mockResolvedValueOnce({
        operations: [
          { tool_class: 'App\\Tools\\Email', tool_name: 'email', operation: 'send', effective_enabled: true, effective_requires_approval: true },
          { tool_class: 'App\\Tools\\Email', tool_name: 'email', operation: 'draft', effective_enabled: true, effective_requires_approval: false },
          { tool_class: 'App\\Tools\\Search', tool_name: 'serper_search', operation: 'query', effective_enabled: true, effective_requires_approval: false },
        ],
      })

      const result = await getAllOperationOverrides(1)

      expect(result.email).toEqual({
        send: { enabled: true, requiresApproval: true },
        draft: { enabled: true, requiresApproval: false },
      })
      expect(result.serper_search).toEqual({
        query: { enabled: true, requiresApproval: false },
      })
    })

    it('returns an empty object for an empty operations list', async () => {
      mockApi.get.mockResolvedValueOnce({ operations: [] })
      expect(await getAllOperationOverrides(1)).toEqual({})
    })
  })

  describe('patchOperationOverride', () => {
    it('PATCHes with the given patch payload', async () => {
      mockApi.patch.mockResolvedValueOnce({})

      await patchOperationOverride(1, 'email', 'send', { enabled: false })

      expect(mockApi.patch).toHaveBeenCalledWith(
        '/agents/1/tools/email/operations/send',
        { enabled: false },
      )
    })
  })

  describe('getLLMConfig / putLLMConfig', () => {
    it('GETs the LLM tool override', async () => {
      const settings = { provider: 'openai', model: 'gpt-4o' }
      mockApi.get.mockResolvedValueOnce({ settings })
      const result = await getLLMConfig(1)
      expect(mockApi.get).toHaveBeenCalledWith('/agents/1/tools/llm_configuration/override')
      expect(result).toEqual(settings)
    })

    it('PUTs the LLM tool override', async () => {
      const settings = { provider: 'openai', model: 'gpt-4o' }
      mockApi.put.mockResolvedValueOnce({ settings })
      const result = await putLLMConfig(1, settings)
      expect(mockApi.put).toHaveBeenCalledWith(
        '/agents/1/tools/llm_configuration/override',
        { settings },
      )
      expect(result).toEqual(settings)
    })
  })
})
