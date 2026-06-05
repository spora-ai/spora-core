import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import ComposerInput from '@/components/ComposerInput.vue'

const global = { stubs: { Icon: true } }

beforeEach(() => {
  setActivePinia(createPinia())
})

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error { status = 0 },
}))

describe('ComposerInput', () => {
  it('renders a textarea', () => {
    const wrapper = mount(ComposerInput, { global })
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('emits an event when the user types into the textarea', async () => {
    const wrapper = mount(ComposerInput, { global })
    const textarea = wrapper.find('textarea')
    await textarea.setValue('hello world')
    expect(textarea.element.value).toBe('hello world')
  })

  it('renders without throwing when disabled', () => {
    const wrapper = mount(ComposerInput, { props: { disabled: true }, global })
    expect(wrapper.html()).toBeTruthy()
  })

  it('renders a textarea with the default placeholder', () => {
    const wrapper = mount(ComposerInput, { global })
    expect(wrapper.find('textarea').attributes('placeholder')).toBeTruthy()
  })
})
