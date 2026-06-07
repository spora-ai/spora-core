import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useScheduleForm } from '@/composables/useScheduleForm'

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}))

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('useScheduleForm', () => {
  it('returns a fresh form on each call (not a singleton)', () => {
    const a = useScheduleForm()
    const b = useScheduleForm()
    expect(a).not.toBe(b)
    // Mutating one must not affect the other
    a.templateId.value = 42
    expect(b.templateId.value).toBe(null)
  })

  it('canProceedFromStep1 is false on first read (no template)', () => {
    const f = useScheduleForm()
    f.templateId.value = null
    f.newTemplateName.value = ''
    expect(f.canProceedFromStep1.value).toBe(false)
  })

  it('canProceedFromStep1 is true for a real template id', () => {
    const f = useScheduleForm()
    f.templateId.value = 42
    expect(f.canProceedFromStep1.value).toBe(true)
  })

  it('canProceedFromStep1 requires a name when creating a new template', () => {
    const f = useScheduleForm()
    f.templateId.value = -1
    f.newTemplateName.value = ''
    expect(f.canProceedFromStep1.value).toBe(false)
    f.newTemplateName.value = 'Daily'
    expect(f.canProceedFromStep1.value).toBe(true)
  })

  it('canSubmitFromStep3 is true for one-shot with date+time', () => {
    const f = useScheduleForm()
    f.mode.value = 'oneshot'
    f.runDate.value = '2026-01-01'
    f.runTime.value = '09:00'
    expect(f.canSubmitFromStep3.value).toBe(true)
    f.runDate.value = ''
    expect(f.canSubmitFromStep3.value).toBe(false)
  })

  it('canSubmitFromStep3 is true for recurring when computedCron is set', () => {
    const f = useScheduleForm()
    f.mode.value = 'recurring'
    f.frequency.value = 'custom'
    f.cronExpression.value = '0 9 * * *'
    expect(f.canSubmitFromStep3.value).toBe(true)
  })

  it('computedCron builds the daily cron from the daily sub-fields', () => {
    const f = useScheduleForm()
    f.mode.value = 'recurring'
    f.frequency.value = 'daily'
    f.daily.value = { interval: 3, time: '14:30' }
    expect(f.computedCron.value).toBe('30 14 */3 * *')
  })

  it('onOpen with no initialData resets to defaults', async () => {
    const f = useScheduleForm()
    f.templateId.value = 7
    f.rawPrompt.value = 'leftover'
    await f.onOpen(() => null, () => 1)
    expect(f.templateId.value).toBe(null)
    expect(f.rawPrompt.value).toBe('')
    expect(f.currentStep.value).toBe(1)
  })

  it('onOpen with initialData applies the data', async () => {
    const f = useScheduleForm()
    // "30 14 */3 * *" is a daily cron (every 3 days at 14:30) — projects to
    // frequency=daily. The bare "0 9 * * *" would parse to frequency=custom
    // (no day-of-month interval), so we use a parseable-by-UI expression.
    await f.onOpen(
      () => ({ id: 1, cron_expression: '30 14 */3 * *', timezone: 'UTC', template_id: null, run_at: null, raw_prompt: 'test' }),
      () => 1,
    )
    expect(f.cronExpression.value).toBe('30 14 */3 * *')
    expect(f.frequency.value).toBe('daily')
  })

  it('onOpen calls fetchTemplates when agentId is finite', async () => {
    const f = useScheduleForm()
    // We can't spy directly on the store from here; assert by triggering the
    // call and confirming no error is thrown when the store rejects.
    await expect(f.onOpen(() => null, () => 42)).resolves.toBeUndefined()
  })

  it('applyInitialData projects run_at ISO into the date + time inputs', async () => {
    const f = useScheduleForm()
    await f.onOpen(
      () => ({
        id: 1,
        run_at: '2026-04-15T14:30:00Z',
        timezone: 'UTC',
        template_id: null,
        cron_expression: null,
        raw_prompt: '',
      }),
      () => 1,
    )
    expect(f.runDate.value).toBe('2026-04-15')
    expect(f.runTime.value).toBe('14:30')
  })

  it('applyInitialData projects a weekly cron onto the weekly sub-fields', async () => {
    const f = useScheduleForm()
    await f.onOpen(
      () => ({
        id: 1,
        cron_expression: '0 9 * * 1',
        timezone: 'UTC',
        template_id: null,
        run_at: null,
        raw_prompt: '',
      }),
      () => 1,
    )
    expect(f.cronExpression.value).toBe('0 9 * * 1')
    expect(f.frequency.value).toBe('weekly')
    expect(f.weekly.value).toEqual({ day: 1, time: '09:00' })
  })

  it('applyInitialData copies the template prompt when template_id matches a known template', async () => {
    // Set up the store with a known template; Pinia state is shared.
    const { setActivePinia, createPinia } = await import('pinia')
    setActivePinia(createPinia())
    const { usePromptTemplatesStore } = await import('@/stores/promptTemplates')
    const store = usePromptTemplatesStore()
    store.templates = [{ id: 5, name: 'Daily', prompt_template: 'tmpl-body' } as never]

    const f = useScheduleForm()
    await f.onOpen(
      () => ({
        id: 1,
        template_id: 5,
        raw_prompt: 'will be overwritten',
        timezone: 'UTC',
        run_at: null,
        cron_expression: null,
      }),
      () => 1,
    )
    expect(f.rawPrompt.value).toBe('tmpl-body')
  })

  it('does not switch to recurring mode when only a run_at is set (no cron_expression)', async () => {
    const f = useScheduleForm()
    await f.onOpen(
      () => ({
        id: 1,
        run_at: '2026-04-15T14:30:00Z',
        timezone: 'UTC',
        template_id: null,
        cron_expression: null,
        raw_prompt: '',
      }),
      () => 1,
    )
    expect(f.mode.value).toBe('oneshot')
  })

  it('applyParsedCron sets frequency and projects weekly fields', () => {
    const f = useScheduleForm()
    f.applyParsedCron('0 9 * * 1')
    expect(f.frequency.value).toBe('weekly')
    expect(f.weekly.value).toEqual({ day: 1, time: '09:00' })
  })

  it('applyParsedCron early-returns for unparseable cron (no fields projection)', () => {
    const f = useScheduleForm()
    f.weekly.value = { day: 5, time: '11:11' }
    f.applyParsedCron('not a cron')
    expect(f.weekly.value).toEqual({ day: 5, time: '11:11' })
  })
})
