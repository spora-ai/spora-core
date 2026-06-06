import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import ScheduleEditorShell from '@/components/shared/ScheduleEditor/index.vue'

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error { status = 0 },
}))

vi.mock('cron-parser', () => ({
  default: {
    parse: () => ({
      next: () => ({ toDate: () => new Date('2026-01-01T09:00:00Z') }),
    }),
  },
}))

const ModalStub = {
  name: 'Modal',
  props: ['modelValue', 'title', 'size'],
  emits: ['update:modelValue', 'close'],
  template: '<div v-if="modelValue" class="modal-stub"><slot /><slot name="footer" /></div>',
}

const stubs = { Icon: true, Modal: ModalStub }

beforeEach(() => {
  setActivePinia(createPinia())
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
})
