import { describe, it, expect } from 'vitest'
import {
  SCHEDULE_TOTAL_STEPS,
  SCHEDULE_STEP_LABELS,
  SCHEDULE_FREQUENCY_OPTIONS,
  SCHEDULE_PROMPT_VARIABLES,
  wrapPromptVariable,
  canProceedFromStep1,
} from '@/composables/useScheduleWizard'

describe('useScheduleWizard', () => {
  describe('constants', () => {
    it('exposes total steps and labels', () => {
      expect(SCHEDULE_TOTAL_STEPS).toBe(3)
      expect(SCHEDULE_STEP_LABELS).toHaveLength(3)
    })

    it('exposes frequency options including custom', () => {
      const values = SCHEDULE_FREQUENCY_OPTIONS.map(o => o.value)
      expect(values).toContain('hourly')
      expect(values).toContain('custom')
    })

    it('exposes prompt variables', () => {
      const tokens = SCHEDULE_PROMPT_VARIABLES.map(p => p.token)
      expect(tokens).toContain('current_date')
      expect(tokens).toContain('agent_name')
    })
  })

  describe('wrapPromptVariable', () => {
    it('wraps a token in {{...}}', () => {
      expect(wrapPromptVariable('current_date')).toBe('{{current_date}}')
    })
  })

  describe('canProceedFromStep1', () => {
    it('false when templateId is null', () => {
      expect(canProceedFromStep1(null, '')).toBe(false)
    })

    it('requires a name when templateId === -1 (new)', () => {
      expect(canProceedFromStep1(-1, '')).toBe(false)
      expect(canProceedFromStep1(-1, '   ')).toBe(false)
      expect(canProceedFromStep1(-1, 'Daily')).toBe(true)
    })

    it('true for any other template id', () => {
      expect(canProceedFromStep1(42, '')).toBe(true)
    })
  })
})
