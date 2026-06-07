/**
 * AgentToolActiveSettingsPanel — read-only "Currently Active Settings" view.
 *
 * Asserts the masked values, the source badge, the "no settings" empty state,
 * and the LLM Capabilities section.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import AgentToolActiveSettingsPanel from '@/components/agent/AgentToolActiveSettingsPanel.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const baseTool: ToolSchema = {
  tool_class: 'Spora\\Tools\\web_search',
  tool_name: 'web_search',
  display_name: 'Web Search',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'agent', options: null },
    { key: 'engine', label: 'Engine', type: 'text', description: '', default: 'google', required: false, scope: 'agent', options: null },
    { key: 'model_id', label: 'Model', type: 'text', description: 'Which LLM model to use.', default: 'gpt-4', required: false, scope: 'agent', options: null, expose_to_llm: true },
  ],
}

function mountPanel(settingsWithSource: Parameters<typeof AgentToolActiveSettingsPanel>[0] extends never ? never : Parameters<typeof AgentToolActiveSettingsPanel>['1']['settingsWithSource']) {
  return mount(AgentToolActiveSettingsPanel, {
    props: { tool: baseTool, settingsWithSource },
  })
}

describe('AgentToolActiveSettingsPanel', () => {
  it('renders every field with its masked value and a source label', () => {
    const wrapper = mountPanel({
      api_key: { value: 'sk-123', source: 'agent' },
      engine: { value: 'google', source: 'global' },
      model_id: { value: 'gpt-4', source: 'default' },
    })
    expect(wrapper.text()).toContain('API Key')
    expect(wrapper.text()).toContain('Engine')
    // Password masked to ***
    expect(wrapper.text()).toContain('••••••••')
    // Engine shown unmasked
    expect(wrapper.text()).toContain('google')
    // Source labels present
    expect(wrapper.text()).toMatch(/agent/i)
    expect(wrapper.text()).toMatch(/global/i)
  })

  it('shows the LLM Capabilities section only when expose_to_llm fields exist', () => {
    const wrapper = mountPanel({
      api_key: { value: 'sk-123', source: 'agent' },
      engine: { value: 'google', source: 'global' },
      model_id: { value: 'gpt-4', source: 'default' },
    })
    expect(wrapper.text()).toContain('LLM Capabilities')
    expect(wrapper.text()).toContain('Model')
  })

  it('omits the LLM Capabilities section when no field has expose_to_llm', () => {
    const toolNoLLM: ToolSchema = {
      ...baseTool,
      settings_schema: baseTool.settings_schema.map((f) => ({ ...f, expose_to_llm: false })),
    }
    const wrapper = mount(AgentToolActiveSettingsPanel, {
      props: { tool: toolNoLLM, settingsWithSource: {} },
    })
    expect(wrapper.text()).not.toContain('LLM Capabilities')
  })

  it('shows the "no settings" empty state when the effective set is empty', () => {
    const wrapper = mountPanel({})
    expect(wrapper.text()).toContain('Using defaults (no settings configured)')
  })
})
