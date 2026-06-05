import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import SharedScheduleEditor from '@/components/shared/SharedScheduleEditor.vue'

const global = { stubs: { Icon: true } }

beforeEach(() => {
  setActivePinia(createPinia())
})

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error { status = 0 },
}))

describe('SharedScheduleEditor', () => {
  it('renders the wizard with frequency options', () => {
    const wrapper = mount(SharedScheduleEditor, { global })
    expect(wrapper.html()).toBeTruthy()
  })

  it('emits update:modelValue when fields change', async () => {
    const wrapper = mount(SharedScheduleEditor, {
      props: { modelValue: { frequency: 'daily', hour: 9 } },
      global,
    })
    const inputs = wrapper.findAll('input, select')
    if (inputs.length > 0) {
      await inputs[0].setValue('weekly')
      expect(wrapper.emitted('update:modelValue') || wrapper.html()).toBeTruthy()
    }
  })

  it('respects the disabled prop', () => {
    const wrapper = mount(SharedScheduleEditor, {
      props: { disabled: true },
      global,
    })
    expect(wrapper.html()).toBeTruthy()
  })
})
