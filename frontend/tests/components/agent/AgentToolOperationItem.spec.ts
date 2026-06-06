import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import AgentToolOperationItem from '@/components/agent/AgentToolOperationItem.vue'

const global = { stubs: { Icon: true } }

const makeProps = (overrides: Record<string, unknown> = {}) => ({
  operationName: 'send_email',
  description: 'Send an email to a recipient',
  enabled: true,
  requiresApproval: true,
  saving: false,
  ...overrides,
})

describe('AgentToolOperationItem', () => {
  describe('operation name and description', () => {
    it('renders the operation name and description', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ operationName: 'send_email', description: 'Send an email to a recipient' }),
        global,
      })

      expect(wrapper.text()).toContain('send_email')
      expect(wrapper.text()).toContain('Send an email to a recipient')
    })
  })

  describe('approval badge', () => {
    it('shows the Auto-approve badge when requires_approval is false', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ requiresApproval: false }),
        global,
      })

      expect(wrapper.text()).toContain('Auto-approve')
      expect(wrapper.text()).not.toContain('Requires approval')
    })

    it('shows the Requires approval badge when requires_approval is true', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ requiresApproval: true }),
        global,
      })

      // Scope to the badge span, not the auto-approve toggle label that
      // also contains the word "Auto-approve".
      const badge = wrapper.find('span[title]')
      expect(badge.text()).toContain('Requires approval')
    })

    it('hides the approval badge when enabled is false', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ enabled: false, requiresApproval: true }),
        global,
      })

      expect(wrapper.text()).not.toContain('Requires approval')
      expect(wrapper.text()).not.toContain('Auto-approve')
    })
  })

  describe('auto-approve toggle', () => {
    it('hides the auto-approve toggle when enabled is false', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ enabled: false }),
        global,
      })

      // The Toggle component is stubbed — check the label for "Auto-approve" text
      expect(wrapper.text()).not.toContain('Auto-approve')
    })

    it('emits toggleAutoApprove when the auto-approve toggle changes', async () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ enabled: true, requiresApproval: true }),
        global,
      })

      // Two Toggle components: auto-approve (only visible when enabled) and enable.
      // The first one is the auto-approve toggle.
      const toggles = wrapper.findAllComponents({ name: 'Toggle' })
      expect(toggles.length).toBeGreaterThanOrEqual(1)

      // Trigger the auto-approve toggle by emitting update:modelValue
      await toggles[0].vm.$emit('update:modelValue', true)

      const events = wrapper.emitted('toggleAutoApprove')
      expect(events).toBeTruthy()
    })
  })

  describe('enable toggle', () => {
    it('emits toggleEnabled when the enable toggle changes', async () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ enabled: true }),
        global,
      })

      // Last Toggle is the enable toggle
      const toggles = wrapper.findAllComponents({ name: 'Toggle' })
      const enableToggle = toggles[toggles.length - 1]
      await enableToggle.vm.$emit('update:modelValue', false)

      const events = wrapper.emitted('toggleEnabled')
      expect(events).toBeTruthy()
    })

    it('disables both toggles while saving', () => {
      const wrapper = mount(AgentToolOperationItem, {
        props: makeProps({ enabled: true, saving: true }),
        global,
      })

      const toggles = wrapper.findAllComponents({ name: 'Toggle' })
      for (const toggle of toggles) {
        expect(toggle.props('disabled')).toBe(true)
      }
    })
  })
})
