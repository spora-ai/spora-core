/**
 * useToolSettingsPanel — pure helpers for ToolSettingsPanel.
 */
import { describe, it, expect } from 'vitest'
import {
  isPasswordField,
  displayValue,
  diffFromGlobalDefaults,
  hasExistingSettings,
  countNonEmptySettings,
  llmExposedFields,
  resolveMode,
} from '@/composables/useToolSettingsPanel'
import type { ToolSchema } from '@/composables/useToolSettings'

const sampleTool: ToolSchema = {
  tool_class: 'TestTool',
  tool_name: 'test',
  display_name: 'Test',
  category: 'general',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, options: null, expose_to_llm: false },
    { key: 'model', label: 'Model', type: 'text', description: '', default: 'gpt-4', required: false, options: null, expose_to_llm: true },
  ],
  operations: [],
}

describe('useToolSettingsPanel helpers', () => {
  describe('isPasswordField', () => {
    it('true for a password field', () => {
      expect(isPasswordField(sampleTool, 'api_key')).toBe(true)
    })

    it('false for a non-password field', () => {
      expect(isPasswordField(sampleTool, 'model')).toBe(false)
    })

    it('false for an unknown key', () => {
      expect(isPasswordField(sampleTool, 'nonexistent')).toBe(false)
    })
  })

  describe('displayValue', () => {
    it('returns "—" for empty', () => {
      expect(displayValue(sampleTool, 'model', '')).toBe('—')
      expect(displayValue(sampleTool, 'model', null as unknown as string)).toBe('—')
    })

    it('masks a password field', () => {
      expect(displayValue(sampleTool, 'api_key', 'sk-secret')).toBe('••••••••')
    })

    it('passes through a non-password value', () => {
      expect(displayValue(sampleTool, 'model', 'gpt-4')).toBe('gpt-4')
    })
  })

  describe('diffFromGlobalDefaults', () => {
    it('includes only fields that differ from defaults', () => {
      const out = diffFromGlobalDefaults(
        { api_key: 'override', model: 'gpt-4' },
        { api_key: '', model: 'gpt-4' },
      )
      expect(out).toEqual({ api_key: 'override' })
    })

    it('treats undefined globalDefaults as ""', () => {
      const out = diffFromGlobalDefaults({ a: 'x' }, undefined)
      expect(out).toEqual({ a: 'x' })
    })

    it('returns empty object when nothing differs', () => {
      const out = diffFromGlobalDefaults({ a: 'x' }, { a: 'x' })
      expect(out).toEqual({})
    })
  })

  describe('hasExistingSettings', () => {
    it('true when any value is non-empty', () => {
      expect(hasExistingSettings({ a: 'x' })).toBe(true)
    })

    it('false when all values are empty', () => {
      expect(hasExistingSettings({ a: '', b: '' })).toBe(false)
      expect(hasExistingSettings({})).toBe(false)
    })
  })

  describe('countNonEmptySettings', () => {
    it('counts non-empty values', () => {
      expect(countNonEmptySettings({ a: 'x', b: '', c: 'y' })).toBe(2)
      expect(countNonEmptySettings({})).toBe(0)
    })
  })

  describe('llmExposedFields', () => {
    it('returns only fields with expose_to_llm: true', () => {
      const out = llmExposedFields(sampleTool)
      expect(out).toHaveLength(1)
      expect(out[0].key).toBe('model')
    })
  })

  describe('resolveMode', () => {
    it('defaults to "global" when undefined', () => {
      expect(resolveMode(undefined)).toBe('global')
    })

    it('passes through explicit mode', () => {
      expect(resolveMode('user')).toBe('user')
      expect(resolveMode('global')).toBe('global')
    })
  })
})
