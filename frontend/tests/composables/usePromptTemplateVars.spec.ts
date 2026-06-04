/**
 * usePromptTemplateVars — pure helpers for the PromptTemplateDialog.
 */
import { describe, it, expect } from 'vitest'
import {
  PROMPT_TEMPLATE_SYSTEM_VARS,
  detectVariables,
  buildVariablesPayload,
  buildTemplatePayload,
  seedSystemVariableValues,
} from '@/composables/usePromptTemplateVars'

describe('usePromptTemplateVars helpers', () => {
  describe('PROMPT_TEMPLATE_SYSTEM_VARS', () => {
    it('exposes the canonical system variables', () => {
      expect(PROMPT_TEMPLATE_SYSTEM_VARS).toContain('current_time')
      expect(PROMPT_TEMPLATE_SYSTEM_VARS).toContain('current_date')
      expect(PROMPT_TEMPLATE_SYSTEM_VARS).toContain('current_datetime')
    })
  })

  describe('detectVariables', () => {
    it('returns [] when no placeholders exist', () => {
      expect(detectVariables('Hello world')).toEqual([])
    })

    it('detects a simple variable', () => {
      const vars = detectVariables('Hello {{name}}')
      expect(vars).toHaveLength(1)
      expect(vars[0].key).toBe('name')
      expect(vars[0].defaultValue).toBe('')
      expect(vars[0].isSystem).toBe(false)
    })

    it('parses defaults via the `:default` syntax', () => {
      const vars = detectVariables('Hello {{name:World}}')
      expect(vars[0].defaultValue).toBe('World')
    })

    it('deduplicates by key (first occurrence wins)', () => {
      const vars = detectVariables('{{x}} and {{x:other}}')
      expect(vars).toHaveLength(1)
      expect(vars[0].defaultValue).toBe('')
    })

    it('flags system variables', () => {
      const vars = detectVariables('Now is {{current_time}}.')
      expect(vars[0].isSystem).toBe(true)
    })

    it('detects multiple distinct variables', () => {
      const vars = detectVariables('{{a}} {{b}} {{c:default}}')
      expect(vars.map(v => v.key)).toEqual(['a', 'b', 'c'])
    })
  })

  describe('buildVariablesPayload', () => {
    it('excludes system variables', () => {
      const detected = [
        { key: 'name', defaultValue: '', isSystem: false },
        { key: 'current_date', defaultValue: '', isSystem: true },
      ]
      const out = buildVariablesPayload(detected, { name: 'Alex' })
      expect(out).toHaveLength(1)
      expect(out[0].key).toBe('name')
    })

    it('prefers user-supplied value over the parsed default', () => {
      const detected = [{ key: 'name', defaultValue: 'World', isSystem: false }]
      const out = buildVariablesPayload(detected, { name: 'Alex' })
      expect(out[0].default_value).toBe('Alex')
    })

    it('falls back to the parsed default when no value supplied', () => {
      const detected = [{ key: 'name', defaultValue: 'World', isSystem: false }]
      const out = buildVariablesPayload(detected, {})
      expect(out[0].default_value).toBe('World')
    })

    it('omits default_value when both empty', () => {
      const detected = [{ key: 'name', defaultValue: '', isSystem: false }]
      const out = buildVariablesPayload(detected, { name: '' })
      expect(out[0].default_value).toBeUndefined()
    })
  })

  describe('buildTemplatePayload', () => {
    it('trims the name and includes prompt_template + variables', () => {
      const out = buildTemplatePayload({
        name: '  Greeter  ',
        promptTemplate: 'Hi {{name}}!',
        variables: [{ key: 'name', defaultValue: '', isSystem: false }],
        values: { name: 'Alex' },
      })
      expect(out.name).toBe('Greeter')
      expect(out.prompt_template).toBe('Hi {{name}}!')
      expect(out.variables).toHaveLength(1)
      expect(out.variables[0].default_value).toBe('Alex')
    })
  })

  describe('seedSystemVariableValues', () => {
    it('returns the same three keys with ISO-ish values', () => {
      const out = seedSystemVariableValues(new Date('2026-04-15T14:30:00Z'))
      expect(out.current_date).toBe('2026-04-15')
      expect(out.current_datetime).toBe('2026-04-15T14:30')
      expect(out.current_time).toMatch(/^\d{2}:\d{2}$/)
    })
  })
})
