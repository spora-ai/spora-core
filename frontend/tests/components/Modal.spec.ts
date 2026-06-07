/**
 * Modal — reusable dialog with Teleport + backdrop click + size variants.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import Modal from '@/components/Modal.vue'

vi.mock('@/components/ui/Icon.vue', () => ({
  default: { name: 'Icon', template: '<i />' },
}))

describe('Modal', () => {
  it('renders nothing when modelValue is false', () => {
    const wrapper = mount(Modal, { props: { modelValue: false }, attachTo: document.body })
    expect(document.body.querySelector('[role="dialog"], .modal')?.textContent ?? '').toBe('')
    wrapper.unmount()
  })

  it('renders the title and slot when modelValue is true', () => {
    const wrapper = mount(Modal, {
      props: { modelValue: true, title: 'My Modal' },
      slots: { default: '<p>body</p>' },
      attachTo: document.body,
    })
    expect(document.body.textContent ?? '').toContain('My Modal')
    expect(document.body.textContent ?? '').toContain('body')
    wrapper.unmount()
  })

  it('emits update:modelValue(false) and close when the backdrop is clicked', async () => {
    const wrapper = mount(Modal, {
      props: { modelValue: true, title: 't' },
      attachTo: document.body,
    })
    const backdrop = document.body.querySelector('.fixed.inset-0') as HTMLElement | null
    expect(backdrop).toBeTruthy()
    backdrop?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false])
    expect(wrapper.emitted('close')).toBeTruthy()
    wrapper.unmount()
  })

  it('does NOT close on backdrop click when backdropClosable=false', () => {
    const wrapper = mount(Modal, {
      props: { modelValue: true, title: 't', backdropClosable: false },
      attachTo: document.body,
    })
    const backdrop = document.body.querySelector('.fixed.inset-0') as HTMLElement | null
    backdrop?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(wrapper.emitted('close')).toBeFalsy()
    wrapper.unmount()
  })

  it('applies the size class for each variant', () => {
    const sizes = ['sm', 'md', 'lg'] as const
    for (const size of sizes) {
      const wrapper = mount(Modal, { props: { modelValue: true, size, title: 't' }, attachTo: document.body })
      const panel = document.body.querySelector('.rounded-xl') as HTMLElement | null
      expect(panel).toBeTruthy()
      const className = panel?.className ?? ''
      const expected = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl' }[size]
      expect(className).toContain(expected)
      wrapper.unmount()
    }
  })
})
