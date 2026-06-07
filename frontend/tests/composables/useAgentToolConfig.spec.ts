/**
 * useAgentToolConfig — pure helpers for AgentToolConfigModal.
 *
 * The legacy `tests/unit/composables/useAgentToolConfig.spec.ts` covers
 * `buildAgentOverridePayload` and `initFormFromSettingsWithSource`. This
 * extension exercises the additional exports landed alongside the modal
 * refactor.
 */
import { describe, it, expect } from 'vitest'
import {
  initFormFromSettingsWithSource,
  resolveInitialForm,
  diffAgainst,
  buildSubmitPayload,
  getSource,
  maskPasswordValue,
  getSourceBadgeClass,
  getSourceLabel,
  hasAnyEffectiveSettings,
  isPasswordField,
} from '@/composables/useAgentToolConfig'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'

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

describe('useAgentToolConfig — extended exports', () => {
  describe('resolveInitialForm', () => {
    it('is an alias of initFormFromSettingsWithSource', () => {
      const settings: SettingsWithSource = {
        api_key: { value: 'agent-secret', source: 'agent' },
        model: { value: 'gpt-4', source: 'global' },
      }
      expect(resolveInitialForm(settings)).toEqual({ api_key: 'agent-secret' })
    })
  })

  describe('initFormFromSettingsWithSource', () => {
    it('serialises non-string agent-sourced values via String()', () => {
      // Regression for typescript:S6551: was `String(item.value ?? '')` which
      // had a misleading nullish-coalesce that obscured the intent. Now uses
      // `item.value == null ? '' : String(item.value)` so null/undefined are
      // converted to '' and every other value goes through String() (number,
      // boolean, bigint, symbol — the caller's responsibility to ensure the
      // settings value is a primitive before storing it).
      const settings: SettingsWithSource = {
        max_tokens: { value: 42, source: 'agent' },
        enabled: { value: true, source: 'agent' },
        count: { value: 0, source: 'agent' }, // falsy primitive — must still serialize
      }
      const form = initFormFromSettingsWithSource(settings)
      expect(form.max_tokens).toBe('42')
      expect(form.enabled).toBe('true')
      expect(form.count).toBe('0')
    })

    it('returns empty string for null/undefined agent-sourced values', () => {
      const settings: SettingsWithSource = {
        opt1: { value: null, source: 'agent' },
        opt2: { value: undefined, source: 'agent' },
      }
      const form = initFormFromSettingsWithSource(settings)
      expect(form).toEqual({ opt1: '', opt2: '' })
    })
  })

  describe('diffAgainst', () => {
    it('returns flag + key list', () => {
      const out = diffAgainst({ api_key: 'secret' })
      expect(out.agentOverridesExist).toBe(true)
      expect(out.overrideKeys).toEqual(['api_key'])
    })

    it('returns false flag when no overrides', () => {
      const out = diffAgainst({})
      expect(out.agentOverridesExist).toBe(false)
      expect(out.overrideKeys).toEqual([])
    })
  })

  describe('buildSubmitPayload', () => {
    it('wraps the override payload in { settings: ... }', () => {
      const out = buildSubmitPayload(sampleTool, { api_key: 'new', model: '' })
      expect(out).toEqual({
        settings: { api_key: 'new', model: null },
      })
    })
  })

  describe('getSource', () => {
    it('returns the source from the map', () => {
      const settings: SettingsWithSource = {
        api_key: { value: 'x', source: 'agent' },
      }
      expect(getSource(settings, 'api_key')).toBe('agent')
    })

    it('returns "default" when the key is missing', () => {
      expect(getSource({}, 'api_key')).toBe('default')
    })
  })

  describe('maskPasswordValue', () => {
    it('returns "—" for null/undefined', () => {
      expect(maskPasswordValue(null, true)).toBe('—')
      expect(maskPasswordValue(undefined, false)).toBe('—')
    })

    it('masks password values', () => {
      expect(maskPasswordValue('secret', true)).toBe('••••••••')
    })

    it('returns the stringified value when not a password', () => {
      expect(maskPasswordValue('plain', false)).toBe('plain')
      expect(maskPasswordValue(42, false)).toBe('42')
      expect(maskPasswordValue(true, false)).toBe('true')
    })

    it('JSON-stringifies arrays and objects (no [object Object])', () => {
      expect(maskPasswordValue(['a', 'b'], false)).toBe('["a","b"]')
      expect(maskPasswordValue({ foo: 1 }, false)).toBe('{"foo":1}')
    })
  })

  describe('getSourceBadgeClass', () => {
    it('returns distinct classes per source', () => {
      const agent = getSourceBadgeClass('agent')
      const user = getSourceBadgeClass('user')
      const global = getSourceBadgeClass('global')
      const dflt = getSourceBadgeClass('default')
      expect(new Set([agent, user, global, dflt]).size).toBe(4)
    })

    it('falls back to the default class for an unknown source', () => {
      const dflt = getSourceBadgeClass('default')
      expect(getSourceBadgeClass('unknown')).toBe(dflt)
    })
  })

  describe('getSourceLabel', () => {
    it('returns human-readable labels', () => {
      expect(getSourceLabel('agent')).toBe('Agent')
      expect(getSourceLabel('user')).toBe('User')
      expect(getSourceLabel('global')).toBe('Global')
      expect(getSourceLabel('default')).toBe('Default')
      expect(getSourceLabel('unknown')).toBe('Default')
    })
  })

  describe('hasAnyEffectiveSettings', () => {
    it('true when at least one source is non-default', () => {
      const settings: SettingsWithSource = {
        a: { value: 'x', source: 'agent' },
        b: { value: 'y', source: 'default' },
      }
      expect(hasAnyEffectiveSettings(settings)).toBe(true)
    })

    it('false when every source is default', () => {
      const settings: SettingsWithSource = {
        a: { value: 'x', source: 'default' },
      }
      expect(hasAnyEffectiveSettings(settings)).toBe(false)
    })

    it('false for empty map', () => {
      expect(hasAnyEffectiveSettings({})).toBe(false)
    })
  })

  describe('isPasswordField', () => {
    it('true for password-typed schema fields', () => {
      expect(isPasswordField(sampleTool, 'api_key')).toBe(true)
    })

    it('false for non-password fields', () => {
      expect(isPasswordField(sampleTool, 'model')).toBe(false)
    })

    it('false for null tool', () => {
      expect(isPasswordField(null, 'api_key')).toBe(false)
    })

    it('false for unknown key', () => {
      expect(isPasswordField(sampleTool, 'nope')).toBe(false)
    })
  })
})
