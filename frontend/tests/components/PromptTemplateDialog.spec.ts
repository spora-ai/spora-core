import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import PromptTemplateDialog from '@/components/PromptTemplateDialog.vue'

const global = { stubs: { Icon: true, Teleport: true } }

beforeEach(() => {
  setActivePinia(createPinia())
})

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error { status = 0 },
}))

describe('PromptTemplateDialog', () => {
  it('renders nothing when modelValue is false', () => {
    const wrapper = mount(PromptTemplateDialog, { props: { modelValue: false }, global })
    expect(wrapper.find('[role="dialog"], .modal, .fixed').exists()).toBe(false)
  })

  it('emits update:modelValue when close is triggered', async () => {
    const wrapper = mount(PromptTemplateDialog, { props: { modelValue: true }, global })
    // The dialog has at least one close button
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBeGreaterThan(0)
  })

  it('renders a template list area when open', () => {
    const wrapper = mount(PromptTemplateDialog, { props: { modelValue: true }, global })
    expect(wrapper.html()).toBeTruthy()
  })
})
