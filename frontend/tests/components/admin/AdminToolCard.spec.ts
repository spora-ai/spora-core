/**
 * AdminToolCard — single tool config card with a settings form.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ApiError } from '@/api/client'

const putSettingsMock = vi.fn()

vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: () => ({ putSettings: putSettingsMock }),
}))

import AdminToolCard from '@/components/admin/AdminToolCard.vue'

const tool = {
  tool_class: 'Spora\\Tools\\WebSearch',
  tool_name: 'web_search',
  display_name: 'Web Search',
  description: 'Search the web',
  category: 'web',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password' as const, required: true, default: '', description: '', options: null, expose_to_llm: false },
  ],
  operations: [],
}

beforeEach(() => {
  putSettingsMock.mockReset()
})

function mountCard(props: { tool?: typeof tool; settings?: Record<string, string>; saving?: boolean; error?: string | null } = {}) {
  return mount(AdminToolCard, {
    props: {
      tool: props.tool ?? tool,
      settings: props.settings ?? {},
      saving: props.saving ?? false,
      error: props.error ?? null,
    },
  })
}

describe('AdminToolCard', () => {
  it('renders the tool display name', () => {
    const wrapper = mountCard()
    expect(wrapper.text()).toContain('Web Search')
  })

  it('falls back to tool_name when display_name is null', () => {
    const wrapper = mountCard({ tool: { ...tool, display_name: null } })
    expect(wrapper.text()).toContain('web_search')
  })

  it('shows the error prop in the settings form', () => {
    const wrapper = mountCard({ error: 'Server error' })
    expect(wrapper.text()).toContain('Server error')
  })

  it('passes settings down to the form as initialSettings', () => {
    // When a password setting is '***' (masked), the form shows a locked
    // display with a "Change" button instead of a real input.
    const wrapper = mountCard({ settings: { api_key: '***' } })
    expect(wrapper.text()).toContain('••••••••')
    expect(wrapper.text()).toContain('Change')
  })

  it('shows a success banner after a successful save', async () => {
    const textTool = { ...tool, settings_schema: [{ key: 'region', label: 'Region', type: 'text' as const, required: false, default: '', description: '', options: null, expose_to_llm: false }] }
    putSettingsMock.mockResolvedValue({ region: 'us-east' })
    const wrapper = mountCard({ tool: textTool, settings: { region: 'us-east' } })
    await wrapper.find('input[type="text"]').setValue('eu-west')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(putSettingsMock).toHaveBeenCalledWith('web_search', expect.objectContaining({ region: 'eu-west' }), expect.any(Object))
    expect(wrapper.emitted('save')).toBeTruthy()
    expect(wrapper.text()).toContain('Saved!')
  })

  it('shows a local error when putSettings throws an ApiError', async () => {
    putSettingsMock.mockRejectedValue(new ApiError('Bad config'))
    const wrapper = mountCard()
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('Bad config')
  })

  it('shows a fallback error when putSettings throws a generic Error', async () => {
    putSettingsMock.mockRejectedValue(new Error('boom'))
    const wrapper = mountCard()
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('Failed to save settings.')
  })
})
