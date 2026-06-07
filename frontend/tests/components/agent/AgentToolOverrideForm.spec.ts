/**
 * AgentToolOverrideForm — per-field override inputs.
 *
 * Asserts that:
 *  - the form initialises from `settingsWithSource`
 *  - the "Remove all" button only appears when rawOverride has values
 *  - emitting @update:form bubbles the latest snapshot
 *  - the source badge shows on non-default fields
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import AgentToolOverrideForm from '@/components/agent/AgentToolOverrideForm.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const baseTool: ToolSchema = {
  tool_class: 'Spora\\Tools\\web_search',
  tool_name: 'web_search',
  display_name: 'Web Search',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'agent', options: null },
    { key: 'engine', label: 'Engine', type: 'text', description: '', default: 'google', required: false, scope: 'agent', options: null },
  ],
}

function mountForm(overrides: { rawOverride?: Record<string, string>; settingsWithSource?: Record<string, { value: unknown; source: string }> } = {}) {
  return mount(AgentToolOverrideForm, {
    props: {
      tool: baseTool,
      settingsWithSource: overrides.settingsWithSource ?? {
        api_key: { value: 'sk-123', source: 'agent' },
        engine: { value: 'google', source: 'global' },
      },
      rawOverride: overrides.rawOverride ?? { api_key: 'sk-123' },
    },
  })
}

describe('AgentToolOverrideForm', () => {
  it('renders every schema field', () => {
    const wrapper = mountForm()
    expect(wrapper.text()).toContain('API Key')
    expect(wrapper.text()).toContain('Engine')
  })

  it('shows the "Remove all agent overrides" button when rawOverride has values', () => {
    const wrapper = mountForm({ rawOverride: { api_key: 'sk-123' } })
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Remove all'))
    expect(btn).toBeDefined()
  })

  it('hides the "Remove all" button when rawOverride is empty', () => {
    const wrapper = mountForm({ rawOverride: {} })
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Remove all'))
    expect(btn).toBeUndefined()
  })

  it('emits @remove-all when the "Remove all" button is clicked', async () => {
    const wrapper = mountForm()
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Remove all'))!
    await btn.trigger('click')
    expect(wrapper.emitted('remove-all')).toBeTruthy()
  })

  it('emits @update:form when an input is typed into', async () => {
    const wrapper = mountForm()
    const input = wrapper.find('input')
    expect(input.exists()).toBe(true)
    await input.setValue('new-value')
    expect(wrapper.emitted('update:form')).toBeTruthy()
  })

  it('shows the source badge for non-default fields', () => {
    const wrapper = mountForm()
    expect(wrapper.text()).toMatch(/agent/i)
    expect(wrapper.text()).toMatch(/global/i)
  })

  it('shows the required asterisk for required fields', () => {
    const requiredTool: ToolSchema = {
      ...baseTool,
      settings_schema: baseTool.settings_schema.map((f, i) => i === 0 ? { ...f, required: true } : f),
    }
    const wrapper = mount(AgentToolOverrideForm, {
      props: { tool: requiredTool, settingsWithSource: {}, rawOverride: {} },
    })
    expect(wrapper.text()).toContain('*')
  })
})
