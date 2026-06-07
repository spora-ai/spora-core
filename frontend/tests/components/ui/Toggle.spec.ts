/**
 * Toggle — switch component (v-model, disabled, size).
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import Toggle from '@/components/ui/Toggle.vue'

describe('Toggle', () => {
  it('renders unchecked when modelValue is false', () => {
    const wrapper = mount(Toggle, { props: { modelValue: false } })
    expect(wrapper.attributes('aria-checked')).toBe('false')
  })

  it('renders checked when modelValue is true', () => {
    const wrapper = mount(Toggle, { props: { modelValue: true } })
    expect(wrapper.attributes('aria-checked')).toBe('true')
  })

  it('emits update:modelValue with the toggled value on click', async () => {
    const wrapper = mount(Toggle, { props: { modelValue: false } })
    await wrapper.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })

  it('does NOT emit when disabled', async () => {
    const wrapper = mount(Toggle, { props: { modelValue: false, disabled: true } })
    await wrapper.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeFalsy()
  })

  it('applies size sm classes', () => {
    const wrapper = mount(Toggle, { props: { modelValue: false, size: 'sm' } })
    expect(wrapper.classes().join(' ')).toMatch(/h-5 w-9/)
  })

  it('applies size md classes by default', () => {
    const wrapper = mount(Toggle, { props: { modelValue: false } })
    expect(wrapper.classes().join(' ')).toMatch(/h-6 w-11/)
  })

  it('applies activeClass when on and inactiveClass when off', () => {
    const on = mount(Toggle, { props: { modelValue: true, activeClass: 'bg-green-500', inactiveClass: 'bg-red-500' } })
    expect(on.classes().join(' ')).toContain('bg-green-500')
    const off = mount(Toggle, { props: { modelValue: false, activeClass: 'bg-green-500', inactiveClass: 'bg-red-500' } })
    expect(off.classes().join(' ')).toContain('bg-red-500')
  })
})
