/**
 * ScheduleRecurringStep — the most complex step. Renders a frequency picker
 * + one of four per-frequency panels (hourly/daily/weekly/monthly) or a
 * custom-cron text input, plus a live next-3-runs preview.
 *
 * The v-model proxies coerce numbers, so we also exercise that path.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref, computed } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

// Mocks the cron-parser default export so `previewRuns` returns a stable list.
// vi.hoisted runs before vi.mock factories, which are themselves hoisted to the
// top of the file, so we keep the impl in hoisted() and mutate it in tests.
const { cronParseImpl } = vi.hoisted(() => ({
  cronParseImpl: () => ({
    next: () => ({ toDate: () => new Date('2026-01-01T09:00:00Z') }),
  }),
}))
vi.mock('cron-parser', () => ({
  default: { parse: cronParseImpl },
}))

vi.mock('@/stores/promptTemplates', () => ({
  usePromptTemplatesStore: () => ({ templates: [] }),
}))

import ScheduleRecurringStep from '@/components/shared/ScheduleEditor/ScheduleRecurringStep.vue'
import type { ScheduleForm } from '@/composables/useScheduleForm'

type AnyForm = Record<string, any>

function makeForm(overrides: Partial<AnyForm> = {}): ScheduleForm {
  const form: AnyForm = {
    currentStep: ref(3),
    error: ref<string | null>(null),
    saving: ref(false),
    mode: ref<'oneshot' | 'recurring'>('recurring'),
    frequency: ref('daily'),
    cronExpression: ref(''),
    runDate: ref(''),
    runTime: ref(''),
    timezone: ref('UTC'),
    rawPrompt: ref(''),
    templateId: ref<number | null>(null),
    newTemplateName: ref(''),
    showCreateTemplate: ref(false),
    maxStepsOverride: ref<number | null>(null),
    hourly: ref({ interval: 1, startHour: 0, endHour: 23, minute: 0 }),
    daily: ref({ interval: 1, time: '09:00' }),
    weekly: ref({ day: 1, time: '09:00' }),
    monthly: ref({ day: 1, time: '09:00' }),
    allTimezones: ['UTC', 'Europe/Berlin'],
    commonZoneValues: new Set(['UTC', 'Europe/Berlin']),
    computedCron: computed(() => '0 9 * * *'),
    canProceedFromStep1: computed(() => true),
    canSubmitFromStep3: computed(() => true),
    canSubmit: computed(() => true),
    applyParsedCron: vi.fn(),
    applyInitialData: vi.fn(),
    onOpen: vi.fn(),
  }
  Object.assign(form, overrides)
  return form as unknown as ScheduleForm
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ScheduleRecurringStep', () => {
  it('renders the frequency selector and timezone selector by default', () => {
    const wrapper = mount(ScheduleRecurringStep, { props: { form: makeForm() } })
    expect(wrapper.find('#schedule-frequency').exists()).toBe(true)
    expect(wrapper.find('#schedule-timezone').exists()).toBe(true)
  })

  it('shows the daily panel when frequency is daily', () => {
    const wrapper = mount(ScheduleRecurringStep, { props: { form: makeForm() } })
    expect(wrapper.find('input[type="number"][min="1"][max="31"]').exists()).toBe(true)
    expect(wrapper.find('input[type="time"]').exists()).toBe(true)
  })

  it('switches to the hourly panel when frequency becomes hourly', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    form.frequency.value = 'hourly'
    await flushPromises()
    const numbers = wrapper.findAll('input[type="number"]')
    expect(numbers.length).toBe(4)
  })

  it('switches to the weekly panel and renders the day-of-week select', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    form.frequency.value = 'weekly'
    await flushPromises()
    const selects = wrapper.findAll('select')
    expect(selects.length).toBeGreaterThanOrEqual(2)
  })

  it('switches to the monthly panel when frequency is monthly', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    form.frequency.value = 'monthly'
    await flushPromises()
    expect(wrapper.find('input[type="number"][min="1"][max="31"]').exists()).toBe(true)
  })

  it('writes monthly day changes back to form.monthly.day', async () => {
    const form = makeForm()
    form.frequency.value = 'monthly'
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    await flushPromises()
    const dayInput = wrapper.find('input[type="number"][min="1"][max="31"]')
    await dayInput.setValue('15')
    expect(form.monthly.value.day).toBe(15)
  })

  it('writes monthly time changes back to form.monthly.time', async () => {
    const form = makeForm()
    form.frequency.value = 'monthly'
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    await flushPromises()
    const timeInput = wrapper.find('input[type="time"]')
    await timeInput.setValue('16:45')
    expect(form.monthly.value.time).toBe('16:45')
  })

  it('writes cron expression changes back to form.cronExpression when frequency is custom', async () => {
    const form = makeForm()
    form.frequency.value = 'custom'
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    await flushPromises()
    const cronInput = wrapper.find('#schedule-cron')
    await cronInput.setValue('*/15 * * * *')
    expect(form.cronExpression.value).toBe('*/15 * * * *')
  })

  it('shows the cron-expression text input when frequency is custom', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    form.frequency.value = 'custom'
    await flushPromises()
    expect(wrapper.find('#schedule-cron').exists()).toBe(true)
  })

  it('writes frequency changes back to form.frequency', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    const freq = wrapper.find('#schedule-frequency')
    await freq.setValue('weekly')
    expect(form.frequency.value).toBe('weekly')
  })

  it('writes timezone changes back to form.timezone', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    const tz = wrapper.find('#schedule-timezone')
    await tz.setValue('Europe/Berlin')
    expect(form.timezone.value).toBe('Europe/Berlin')
  })

  it('coerces the daily interval number input to a number', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    const intervalInput = wrapper.find('input[type="number"][min="1"][max="31"]')
    await intervalInput.setValue('3')
    expect(form.daily.value.interval).toBe(3)
  })

  it('writes daily time input back to form.daily.time', async () => {
    const form = makeForm()
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    const time = wrapper.find('input[type="time"]')
    await time.setValue('14:30')
    expect(form.daily.value.time).toBe('14:30')
  })

  it('shows the preview-runs list when computedCron is non-empty', () => {
    const wrapper = mount(ScheduleRecurringStep, { props: { form: makeForm() } })
    expect(wrapper.text()).toMatch(/next 3 runs/i)
    expect(wrapper.findAll('ul li').length).toBe(3)
  })

  it('hides the preview when computedCron is empty', async () => {
    const form = makeForm({ computedCron: computed(() => '') })
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    expect(wrapper.text()).not.toMatch(/next 3 runs/i)
  })

  it('shows the cron-parse error message when computedCron is set but unparseable', async () => {
    // The composable calls CronExpression.parse(cron) and catches — make it throw.
    const mod = await import('cron-parser')
    const original = (mod.default as { parse: () => unknown }).parse
    ;(mod.default as { parse: () => unknown }).parse = () => { throw new Error('bad cron') }
    try {
      const form = makeForm({ computedCron: computed(() => 'invalid-cron') })
      const wrapper = mount(ScheduleRecurringStep, { props: { form } })
      expect(wrapper.text()).toMatch(/Could not parse cron expression/i)
    } finally {
      ;(mod.default as { parse: () => unknown }).parse = original
    }
  })

  it('writes hourly interval, startHour, endHour, minute back to form.hourly', async () => {
    const form = makeForm()
    form.frequency.value = 'hourly'
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    await flushPromises()
    const numbers = wrapper.findAll('input[type="number"]')
    expect(numbers.length).toBe(4)
    await numbers[0]!.setValue('2')
    await numbers[1]!.setValue('8')
    await numbers[2]!.setValue('18')
    await numbers[3]!.setValue('30')
    expect(form.hourly.value).toEqual({ interval: 2, startHour: 8, endHour: 18, minute: 30 })
  })

  it('writes weekly day and time back to form.weekly', async () => {
    const form = makeForm()
    form.frequency.value = 'weekly'
    const wrapper = mount(ScheduleRecurringStep, { props: { form } })
    await flushPromises()
    const selects = wrapper.findAll('select')
    const daySelect = selects.find((s) => s.attributes('id') !== 'schedule-frequency' && s.attributes('id') !== 'schedule-timezone')!
    await daySelect.setValue('3')
    expect(form.weekly.value.day).toBe(3)
    const timeInput = wrapper.find('input[type="time"]')
    await timeInput.setValue('11:15')
    expect(form.weekly.value.time).toBe('11:15')
  })
})
