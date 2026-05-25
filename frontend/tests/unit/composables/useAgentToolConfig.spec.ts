import { describe, it, expect } from 'vitest'
import { buildAgentOverridePayload, initFormFromSettingsWithSource } from '../../../src/composables/useAgentToolConfig'
import type { ToolSchema } from '../../../src/composables/useToolSettings'

describe('useAgentToolConfig', () => {
  describe('buildAgentOverridePayload', () => {
    const makeTool = (fields: Array<{ key: string; label: string; type: string }>): ToolSchema =>
      ({
        name: 'test_tool',
        display_name: 'Test Tool',
        description: 'A test tool',
        settings_schema: fields.map((f) => ({
          ...f,
          required: false,
          encrypted: false,
        })),
      }) as ToolSchema

    it('sends non-empty values as-is', () => {
      const tool = makeTool([
        { key: 'api_key', label: 'API Key', type: 'password' },
        { key: 'max_results', label: 'Max Results', type: 'text' },
      ])

      const form = {
        api_key: 'secret123',
        max_results: '10',
      }

      const result = buildAgentOverridePayload(tool, form)

      expect(result).toEqual({
        api_key: 'secret123',
        max_results: '10',
      })
    })

    it('sends null for empty string values', () => {
      const tool = makeTool([
        { key: 'api_key', label: 'API Key', type: 'password' },
        { key: 'max_results', label: 'Max Results', type: 'text' },
      ])

      const form = {
        api_key: '',
        max_results: '10',
      }

      const result = buildAgentOverridePayload(tool, form)

      expect(result).toEqual({
        api_key: null,
        max_results: '10',
      })
    })

    it('sends null for null form values', () => {
      const tool = makeTool([
        { key: 'api_key', label: 'API Key', type: 'password' },
      ])

      const form: Record<string, string> = {
        api_key: null as unknown as string,
      }

      const result = buildAgentOverridePayload(tool, form)

      expect(result).toEqual({
        api_key: null,
      })
    })

    it('sends null for undefined form values', () => {
      const tool = makeTool([
        { key: 'api_key', label: 'API Key', type: 'password' },
      ])

      const form: Record<string, string> = {
        api_key: undefined as unknown as string,
      }

      const result = buildAgentOverridePayload(tool, form)

      expect(result).toEqual({
        api_key: null,
      })
    })

    it('sends all schema fields even if not in form', () => {
      const tool = makeTool([
        { key: 'api_key', label: 'API Key', type: 'password' },
        { key: 'max_results', label: 'Max Results', type: 'text' },
        { key: 'custom_field', label: 'Custom Field', type: 'text' },
      ])

      const form = {
        api_key: 'secret123',
        // max_results and custom_field are missing
      }

      const result = buildAgentOverridePayload(tool, form)

      expect(result).toEqual({
        api_key: 'secret123',
        max_results: null,
        custom_field: null,
      })
    })
  })

  describe('initFormFromSettingsWithSource', () => {
    it('extracts only agent-scoped values', () => {
      const settings = {
        api_key: { value: 'agent-key', source: 'agent' },
        max_results: { value: '50', source: 'global' },
        custom_field: { value: 'custom-val', source: 'agent' },
      }

      const result = initFormFromSettingsWithSource(settings)

      expect(result).toEqual({
        api_key: 'agent-key',
        custom_field: 'custom-val',
      })
    })

    it('returns empty object when no agent-scoped values', () => {
      const settings = {
        max_results: { value: '50', source: 'global' },
      }

      const result = initFormFromSettingsWithSource(settings)

      expect(result).toEqual({})
    })

    it('converts values to strings', () => {
      const settings = {
        api_key: { value: 123, source: 'agent' },
        max_results: { value: null, source: 'agent' },
      }

      const result = initFormFromSettingsWithSource(settings)

      expect(result).toEqual({
        api_key: '123',
        max_results: '',
      })
    })
  })
})
