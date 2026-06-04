/**
 * useComposerInput — pure helpers for the ComposerInput component.
 */
import { describe, it, expect } from 'vitest'
import {
  COMPOSER_PLACEHOLDER_REGEX,
  snapshotSystemVariables,
  substituteTemplateVariables,
  buildPromptFromTemplate,
  computeTextareaHeight,
  isSubmitKeystroke,
} from '@/composables/useComposerInput'

describe('useComposerInput helpers', () => {
  describe('snapshotSystemVariables', () => {
    it('returns date / time / datetime in ISO-like format', () => {
      const snap = snapshotSystemVariables(new Date('2026-04-15T14:30:00Z'))
      expect(snap.current_date).toBe('2026-04-15')
      expect(snap.current_datetime).toBe('2026-04-15T14:30')
      // current_time depends on local TZ — just check it's HH:MM
      expect(snap.current_time).toMatch(/^\d{2}:\d{2}$/)
    })
  })

  describe('substituteTemplateVariables', () => {
    const systemVars = {
      current_date: '2026-04-15',
      current_time: '14:30',
      current_datetime: '2026-04-15T14:30',
    }

    it('substitutes system vars', () => {
      const out = substituteTemplateVariables('Today is {{current_date}}.', systemVars, null)
      expect(out).toBe('Today is 2026-04-15.')
    })

    it('substitutes a template-variable default when provided', () => {
      const out = substituteTemplateVariables(
        'Hello {{name}}.',
        systemVars,
        [{ key: 'name', default_value: 'Alex' }],
      )
      expect(out).toBe('Hello Alex.')
    })

    it('uses inline default when no template variable matches', () => {
      const out = substituteTemplateVariables('Hello {{name:World}}.', systemVars, null)
      expect(out).toBe('Hello World.')
    })

    it('keeps the original token when nothing matches', () => {
      const out = substituteTemplateVariables('Hello {{unknown}}.', systemVars, null)
      expect(out).toBe('Hello {{unknown}}.')
    })

    it('handles multiple placeholders in one string', () => {
      const out = substituteTemplateVariables(
        '{{current_date}} {{name:Bob}}',
        systemVars,
        null,
      )
      expect(out).toBe('2026-04-15 Bob')
    })
  })

  describe('buildPromptFromTemplate', () => {
    it('substitutes system vars from a snapshot at "now"', () => {
      const out = buildPromptFromTemplate(
        'Today: {{current_date}}',
        null,
        new Date('2026-04-15T00:00:00Z'),
      )
      expect(out).toContain('2026-04-15')
    })
  })

  describe('computeTextareaHeight', () => {
    it('returns scrollHeight when below max', () => {
      expect(computeTextareaHeight(120)).toBe(120)
    })

    it('caps to max (default 300)', () => {
      expect(computeTextareaHeight(500)).toBe(300)
    })

    it('respects a custom max', () => {
      expect(computeTextareaHeight(400, 200)).toBe(200)
    })
  })

  describe('isSubmitKeystroke', () => {
    it('detects Cmd+Enter', () => {
      const e = { key: 'Enter', metaKey: true, ctrlKey: false } as KeyboardEvent
      expect(isSubmitKeystroke(e)).toBe(true)
    })

    it('detects Ctrl+Enter', () => {
      const e = { key: 'Enter', metaKey: false, ctrlKey: true } as KeyboardEvent
      expect(isSubmitKeystroke(e)).toBe(true)
    })

    it('rejects plain Enter', () => {
      const e = { key: 'Enter', metaKey: false, ctrlKey: false } as KeyboardEvent
      expect(isSubmitKeystroke(e)).toBe(false)
    })

    it('rejects other keys with modifier', () => {
      const e = { key: 'a', metaKey: true, ctrlKey: false } as KeyboardEvent
      expect(isSubmitKeystroke(e)).toBe(false)
    })
  })

  describe('COMPOSER_PLACEHOLDER_REGEX', () => {
    it('is a regex with the global flag', () => {
      // Each test gets a fresh exec by reading lastIndex
      const regex = COMPOSER_PLACEHOLDER_REGEX
      expect(regex).toBeInstanceOf(RegExp)
      expect(regex.global).toBe(true)
    })
  })
})
