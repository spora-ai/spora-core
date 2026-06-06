import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref, computed, type Ref, type ComputedRef } from 'vue'

const mocks = vi.hoisted(() => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  createTemplate: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: mocks.api,
  ApiError: class ApiError extends Error { status = 0 },
}))

vi.mock('cron-parser', () => ({
  default: {
    parse: () => ({
      next: () => ({ toDate: () => new Date('2026-01-01T09:00:00Z') }),
    }),
  },
}))

vi.mock('@/stores/promptTemplates', () => ({
  usePromptTemplatesStore: () => ({ createTemplate: mocks.createTemplate }),
}))

// Vue refs/computed are required here — plain objects would not re-render the template on mutation.
interface FormMock {
  currentStep: Ref<number>
  error: Ref<string | null>
  saving: Ref<boolean>
  mode: Ref<'oneshot' | 'recurring'>
  frequency: Ref<string>
  cronExpression: Ref<string>
  runDate: Ref<string>
  runTime: Ref<string>
  timezone: Ref<string>
  rawPrompt: Ref<string>
  templateId: Ref<number | null>
  newTemplateName: Ref<string>
  showCreateTemplate: Ref<boolean>
  maxStepsOverride: Ref<number | null>
  hourly: Ref<{ interval: number; startHour: number; endHour: number; minute: number }>
  daily: Ref<{ interval: number; time: string }>
  weekly: Ref<{ day: number; time: string }>
  monthly: Ref<{ day: number; time: string }>
  allTimezones: string[]
  commonZoneValues: Set<string>
  computedCron: ComputedRef<string>
  canProceedFromStep1: ComputedRef<boolean>
  canSubmitFromStep3: ComputedRef<boolean>
  canSubmit: ComputedRef<boolean>
  onOpen: ReturnType<typeof vi.fn>
  applyParsedCron: ReturnType<typeof vi.fn>
  applyInitialData: ReturnType<typeof vi.fn>
}

let formMock: FormMock

function makeFormMock(): FormMock {
  return {
    currentStep: ref(1),
    error: ref<string | null>(null),
    saving: ref(false),
    mode: ref<'oneshot' | 'recurring'>('oneshot'),
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
    allTimezones: ['UTC'],
    commonZoneValues: new Set(['UTC']),
    computedCron: computed(() => '0 9 * * *'),
    canProceedFromStep1: computed(() => true),
    canSubmitFromStep3: computed(() => true),
    canSubmit: computed(() => true),
    onOpen: vi.fn().mockImplementation(async (getInitialData: () => unknown) => {
      const data = getInitialData() as { template_id?: number; raw_prompt?: string } | null
      if (data) {
        formMock.templateId.value = data.template_id ?? null
        formMock.rawPrompt.value = data.raw_prompt ?? ''
      }
    }),
    applyParsedCron: vi.fn(),
    applyInitialData: vi.fn(),
  }
}

vi.mock('@/composables/useScheduleForm', () => ({
  useScheduleForm: () => formMock,
}))

import ScheduleEditorShell from '@/components/shared/ScheduleEditor/index.vue'

const ModalStub = {
  name: 'Modal',
  props: ['modelValue', 'title', 'size'],
  emits: ['update:modelValue', 'close'],
  template: '<div v-if="modelValue" class="modal-stub"><slot /><slot name="footer" /></div>',
}

const stubs = { Icon: true, Modal: ModalStub }

beforeEach(() => {
  setActivePinia(createPinia())
  formMock = makeFormMock()
  mocks.api.get.mockReset()
  mocks.api.post.mockReset()
  mocks.api.put.mockReset()
  mocks.api.delete.mockReset()
  mocks.createTemplate.mockReset()
})

describe('ScheduleEditor shell', () => {
  it('renders nothing when modelValue is false', async () => {
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: false, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    expect(wrapper.find('.modal-stub').exists()).toBe(false)
  })

  it('renders the modal chrome when modelValue is true', async () => {
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    expect(wrapper.find('.modal-stub').exists()).toBe(true)
  })

  it('uses the "Edit Schedule" title when initialData has an id', async () => {
    const wrapper = mount(ScheduleEditorShell, {
      props: {
        modelValue: true,
        agentId: 1,
        initialData: { id: 99, cron_expression: '0 9 * * *', timezone: 'UTC', template_id: null, run_at: null, raw_prompt: '' },
      },
      global: { stubs },
    })
    await flushPromises()
    expect(wrapper.find('.modal-stub').exists()).toBe(true)
  })

  it('emits "closed" and updates modelValue when the user clicks Cancel', async () => {
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    const buttons = wrapper.findAll('button')
    const cancel = buttons.find((b) => b.text() === 'Cancel')
    expect(cancel).toBeDefined()
    await cancel!.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('closed')).toBeTruthy()
  })

  it('POSTs a new schedule when the submit button is clicked and emits saved', async () => {
    mocks.api.post.mockResolvedValueOnce({ scheduled_run: { id: 7 } })
    formMock.currentStep.value = 3
    formMock.mode.value = 'oneshot'
    formMock.runDate.value = '2026-09-01'
    formMock.runTime.value = '08:00'
    formMock.rawPrompt.value = 'do the thing'
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    const submit = wrapper.find('[data-testid="schedule-submit-button"]')
    await submit.trigger('click')
    await flushPromises()
    expect(mocks.api.post).toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
  })

  it('PUTs an existing schedule when initialData.id is set', async () => {
    mocks.api.put.mockResolvedValueOnce({ scheduled_run: { id: 99 } })
    formMock.currentStep.value = 3
    formMock.mode.value = 'recurring'
    formMock.cronExpression.value = '0 9 * * *'
    const wrapper = mount(ScheduleEditorShell, {
      props: {
        modelValue: true,
        agentId: 1,
        initialData: { id: 99, cron_expression: '0 9 * * *', timezone: 'UTC', template_id: 1, run_at: null, raw_prompt: '' },
      },
      global: { stubs },
    })
    await flushPromises()
    const submit = wrapper.find('[data-testid="schedule-submit-button"]')
    await submit.trigger('click')
    await flushPromises()
    expect(mocks.api.put).toHaveBeenCalled()
    expect(mocks.api.post).not.toHaveBeenCalled()
  })

  it('surfaces an ApiError message on submit failure', async () => {
    const { ApiError } = await import('@/api/client')
    mocks.api.post.mockRejectedValueOnce(new ApiError('invalid cron'))
    formMock.currentStep.value = 3
    formMock.runDate.value = '2026-09-01'
    formMock.runTime.value = '08:00'
    formMock.rawPrompt.value = 'do the thing'
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    await wrapper.find('[data-testid="schedule-submit-button"]').trigger('click')
    await flushPromises()
    expect(formMock.error.value).toBe('invalid cron')
  })

  it('disables the submit button when canSubmit is false', async () => {
    formMock.currentStep.value = 3
    formMock.canSubmit = computed(() => false)
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    const submit = wrapper.find('[data-testid="schedule-submit-button"]')
    expect(submit.attributes('disabled')).toBeDefined()
  })

  it('navigates between steps with the Next/Back buttons', async () => {
    formMock.currentStep.value = 1
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    const next = wrapper.findAll('button').find((b) => b.text() === 'Next')
    expect(next).toBeDefined()
    await next!.trigger('click')
    expect(formMock.currentStep.value).toBe(2)
    const back = wrapper.findAll('button').find((b) => b.text() === 'Back')
    expect(back).toBeDefined()
    await back!.trigger('click')
    expect(formMock.currentStep.value).toBe(1)
  })

  it('creates a new prompt template inline when submit is called with showCreateTemplate + newTemplateName', async () => {
    mocks.createTemplate.mockResolvedValueOnce({ id: 42 })
    mocks.api.post.mockResolvedValueOnce({ scheduled_run: { id: 7 } })
    formMock.currentStep.value = 3
    formMock.showCreateTemplate.value = true
    formMock.newTemplateName.value = 'New template'
    formMock.rawPrompt.value = 'tmpl prompt'
    formMock.templateId.value = -1
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    await wrapper.find('[data-testid="schedule-submit-button"]').trigger('click')
    await flushPromises()
    expect(mocks.createTemplate).toHaveBeenCalledWith(1, { name: 'New template', prompt_template: 'tmpl prompt' })
  })

  it('shows an inline "Please provide a prompt" error when no prompt is set on submit', async () => {
    formMock.currentStep.value = 3
    formMock.runDate.value = '2026-09-01'
    formMock.runTime.value = '08:00'
    formMock.rawPrompt.value = ''
    formMock.templateId.value = null
    const wrapper = mount(ScheduleEditorShell, {
      props: { modelValue: true, agentId: 1 },
      global: { stubs },
    })
    await flushPromises()
    await wrapper.find('[data-testid="schedule-submit-button"]').trigger('click')
    await flushPromises()
    expect(formMock.error.value).toBe('Please provide a prompt.')
    expect(mocks.api.post).not.toHaveBeenCalled()
  })
})
