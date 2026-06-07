/**
 * Toast — auto-dismissing notification.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'

import Toast from '@/components/ui/Toast.vue'

describe('Toast', () => {
  it('renders the message', () => {
    const wrapper = mount(Toast, {
      props: { id: '1', severity: 'info', message: 'Hello', onDismiss: vi.fn() },
    })
    expect(wrapper.text()).toContain('Hello')
  })

  it('renders an action button when an action label is provided', () => {
    const wrapper = mount(Toast, {
      props: { id: '1', severity: 'info', message: 'Hi', action: 'Retry', onAction: vi.fn(), onDismiss: vi.fn() },
    })
    const buttons = wrapper.findAll('button')
    const actionButton = buttons.find((b) => b.text().includes('Retry'))
    expect(actionButton).toBeDefined()
  })
})
