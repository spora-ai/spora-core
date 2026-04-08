import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import AlertBanner from '@/components/ui/AlertBanner.vue'

describe('AlertBanner', () => {
  it('renders the message', () => {
    const wrapper = mount(AlertBanner, { props: { type: 'success', message: 'Saved!' } })
    expect(wrapper.text()).toBe('Saved!')
  })

  it('has role="alert" for accessibility', () => {
    const wrapper = mount(AlertBanner, { props: { type: 'success', message: 'OK' } })
    expect(wrapper.attributes('role')).toBe('alert')
  })

  it('applies green styles for type=success', () => {
    const wrapper = mount(AlertBanner, { props: { type: 'success', message: 'Done' } })
    expect(wrapper.classes()).toContain('bg-green-50')
    expect(wrapper.classes()).toContain('text-green-700')
  })

  it('applies destructive styles for type=error', () => {
    const wrapper = mount(AlertBanner, { props: { type: 'error', message: 'Something went wrong' } })
    expect(wrapper.classes()).toContain('text-destructive')
    expect(wrapper.classes()).toContain('bg-destructive/10')
  })
})
