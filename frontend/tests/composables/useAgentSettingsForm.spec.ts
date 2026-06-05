/**
 * useAgentSettingsForm — pure helpers for AgentSettingsPage.
 */
import { describe, it, expect } from 'vitest'
import {
  categoryLabel,
  groupToolsByCategory,
  sortCategoryKeys,
  formatLlmConfigLabel,
  buildInitialIdentityForm,
  buildInitialLlmSettings,
  buildIdentityPayload,
  buildLlmSettingsPayload,
} from '@/composables/useAgentSettingsForm'

describe('useAgentSettingsForm', () => {
  describe('categoryLabel', () => {
    it('capitalizes the first character', () => {
      expect(categoryLabel('communication')).toBe('Communication')
    })

    it('handles an empty string', () => {
      expect(categoryLabel('')).toBe('')
    })

    it('handles a single character', () => {
      expect(categoryLabel('a')).toBe('A')
    })
  })

  describe('groupToolsByCategory', () => {
    it('groups tools by their category field', () => {
      const tools = [
        { tool_name: 't1', category: 'core' },
        { tool_name: 't2', category: 'core' },
        { tool_name: 't3', category: 'extras' },
      ] as never
      const grouped = groupToolsByCategory(tools)
      expect(Object.keys(grouped).sort()).toEqual(['core', 'extras'])
      expect(grouped.core).toHaveLength(2)
      expect(grouped.extras).toHaveLength(1)
    })

    it('defaults to "general" for tools without a category', () => {
      const tools = [{ tool_name: 't1' }] as never
      const grouped = groupToolsByCategory(tools)
      expect(grouped.general).toHaveLength(1)
    })
  })

  describe('sortCategoryKeys', () => {
    it('returns keys sorted by their human label', () => {
      const cats = { zebra: 1, apple: 1, mango: 1 }
      expect(sortCategoryKeys(cats)).toEqual(['apple', 'mango', 'zebra'])
    })

    it('returns [] for an empty object', () => {
      expect(sortCategoryKeys({})).toEqual([])
    })
  })

  describe('formatLlmConfigLabel', () => {
    it('adds "— Global" suffix when is_global is true', () => {
      const label = formatLlmConfigLabel({
        name: 'Prod',
        driver_display_name: 'OpenAI',
        is_global: true,
      })
      expect(label).toBe('Prod (OpenAI) — Global')
    })

    it('omits "— Global" when is_global is false', () => {
      const label = formatLlmConfigLabel({
        name: 'Personal',
        driver_display_name: 'Anthropic',
        is_global: false,
      })
      expect(label).toBe('Personal (Anthropic)')
    })
  })

  describe('buildInitialIdentityForm', () => {
    it('uses defaults when fields are null/undefined', () => {
      const out = buildInitialIdentityForm({ name: 'A' })
      expect(out).toEqual({
        name: 'A',
        description: '',
        system_prompt: '',
        max_steps: 10,
        allow_continuation: true,
        retry_after_minutes: 0,
        max_retries: 0,
      })
    })

    it('passes through provided values', () => {
      const out = buildInitialIdentityForm({
        name: 'Bot',
        description: 'A bot',
        system_prompt: 'You are helpful',
        max_steps: 25,
        allow_continuation: false,
        retry_after_minutes: 5,
        max_retries: 3,
      })
      expect(out.description).toBe('A bot')
      expect(out.system_prompt).toBe('You are helpful')
      expect(out.max_steps).toBe(25)
      expect(out.allow_continuation).toBe(false)
      expect(out.retry_after_minutes).toBe(5)
      expect(out.max_retries).toBe(3)
    })

    it('treats undefined allow_continuation as true (only explicit false flips it)', () => {
      expect(buildInitialIdentityForm({ name: 'x' }).allow_continuation).toBe(true)
      expect(buildInitialIdentityForm({ name: 'x', allow_continuation: false }).allow_continuation).toBe(false)
      expect(buildInitialIdentityForm({ name: 'x', allow_continuation: true }).allow_continuation).toBe(true)
    })
  })

  describe('buildInitialLlmSettings', () => {
    it('uses null when llm_driver_config_id is missing', () => {
      expect(buildInitialLlmSettings({})).toEqual({ llm_driver_config_id: null })
    })

    it('passes through a numeric id', () => {
      expect(buildInitialLlmSettings({ llm_driver_config_id: 42 })).toEqual({
        llm_driver_config_id: 42,
      })
    })
  })

  describe('buildIdentityPayload', () => {
    it('returns null for empty strings (description/system_prompt)', () => {
      const out = buildIdentityPayload({
        name: 'A',
        description: '',
        system_prompt: '',
        max_steps: 5,
        allow_continuation: true,
        retry_after_minutes: 0,
        max_retries: 0,
      })
      expect(out.description).toBeNull()
      expect(out.system_prompt).toBeNull()
    })

    it('keeps non-empty strings', () => {
      const out = buildIdentityPayload({
        name: 'A',
        description: 'desc',
        system_prompt: 'sp',
        max_steps: 5,
        allow_continuation: false,
        retry_after_minutes: 10,
        max_retries: 2,
      })
      expect(out.description).toBe('desc')
      expect(out.system_prompt).toBe('sp')
      expect(out.max_steps).toBe(5)
      expect(out.allow_continuation).toBe(false)
      expect(out.retry_after_minutes).toBe(10)
      expect(out.max_retries).toBe(2)
    })
  })

  describe('buildLlmSettingsPayload', () => {
    it('wraps the id in a payload object', () => {
      expect(buildLlmSettingsPayload({ llm_driver_config_id: 7 })).toEqual({
        llm_driver_config_id: 7,
      })
      expect(buildLlmSettingsPayload({ llm_driver_config_id: null })).toEqual({
        llm_driver_config_id: null,
      })
    })
  })
})
