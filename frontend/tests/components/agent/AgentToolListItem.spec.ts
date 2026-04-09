import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import AgentToolListItem from '@/components/agent/AgentToolListItem.vue'

const makeTool = (overrides = {}) => ({
  tool_class: 'Spora\\Tools\\WebSearch',
  tool_name: 'web_search',
  display_name: 'Web Search',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
  ],
  ...overrides,
})

describe('AgentToolListItem', () => {
  describe('renders correctly', () => {
    it('shows tool display_name when available', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool({ display_name: 'Web Search', tool_name: 'web_search' }),
          enabled: false,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('Web Search')
    })

    it('falls back to tool_name when display_name is empty', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool({ display_name: '', tool_name: 'web_search' }),
          enabled: false,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('web_search')
    })
  })

  describe('configure button', () => {
    it('shown when enabled=true and tool has settings_schema', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: true,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('Configure')
    })

    it('hidden when enabled=false', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: false,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).not.toContain('Configure')
    })

    it('hidden when tool has no settings_schema', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool({ settings_schema: [] }),
          enabled: true,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).not.toContain('Configure')
    })
  })

  describe('auto-approve toggle', () => {
    it('shown when enabled=true', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: true,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('Auto-approve')
    })

    it('hidden when enabled=false', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: false,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).not.toContain('Auto-approve')
    })
  })

  describe('credentials hint text', () => {
    it('shows "Has credentials to configure" when enabled and has settings_schema', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: true,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('Has credentials to configure')
    })

    it('shows "Enable to configure credentials" when disabled and has settings_schema', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool(),
          enabled: false,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).toContain('Enable to configure credentials')
    })

    it('shows nothing when tool has no settings_schema', () => {
      const wrapper = mount(AgentToolListItem, {
        props: {
          tool: makeTool({ settings_schema: [] }),
          enabled: true,
          autoApproved: false,
          saving: false,
        },
      })
      expect(wrapper.text()).not.toContain('credentials')
    })
  })

  describe('emits', () => {
    it('emits toggle when enable/disable button clicked', async () => {
      const wrapper = mount(AgentToolListItem, {
        props: { tool: makeTool(), enabled: false, autoApproved: false, saving: false },
      })
      await wrapper.findAll('button')[0].trigger('click')
      expect(wrapper.emitted('toggle')).toBeDefined()
    })

    it('emits openConfig when configure button clicked', async () => {
      const wrapper = mount(AgentToolListItem, {
        props: { tool: makeTool(), enabled: true, autoApproved: false, saving: false },
      })
      await wrapper.findAll('button').find((b) => b.text() === 'Configure')!.trigger('click')
      expect(wrapper.emitted('openConfig')).toBeDefined()
    })

    it('emits toggleAutoApprove when auto-approve button clicked', async () => {
      const wrapper = mount(AgentToolListItem, {
        props: { tool: makeTool(), enabled: true, autoApproved: false, saving: false },
      })
      // Find the auto-approve toggle button (not the enable/disable one)
      const buttons = wrapper.findAll('button')
      const autoApproveBtn = buttons.find((b) => b.classes().includes('w-9'))
      await autoApproveBtn!.trigger('click')
      expect(wrapper.emitted('toggleAutoApprove')).toBeDefined()
    })
  })
})
