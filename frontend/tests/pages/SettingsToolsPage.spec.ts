/**
 * SettingsToolsPage — list of tools grouped by category; clicking a tool loads settings panel.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const routeQuery = ref<Record<string, string>>({})
const replaceMock = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: routeQuery.value }),
  useRouter: () => ({ replace: replaceMock, push: vi.fn() }),
}))

const getGlobalSettingsMock = vi.fn().mockResolvedValue({})
vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: () => ({ getGlobalSettings: getGlobalSettingsMock }),
}))

import SettingsToolsPage from '@/pages/settings/SettingsToolsPage.vue'

const tools = [
  { tool_class: 'CalculatorTool', tool_name: 'calculator', display_name: 'Calculator', category: 'general',
    settings_schema: [{ key: 'precision', label: 'Precision', type: 'text', description: '', default: '10', required: false, options: null, expose_to_llm: false }],
    operations: [] },
  { tool_class: 'ScratchpadTool', tool_name: 'scratchpad', display_name: 'Scratchpad', category: 'general',
    settings_schema: [],
    operations: [] },
  { tool_class: 'NewsApiTool', tool_name: 'news_api', display_name: 'News API', category: 'research',
    settings_schema: [{ key: 'api_key', label: 'API Key', type: 'password', description: '', default: '', required: true, options: null, expose_to_llm: false }],
    operations: [] },
  { tool_class: 'GNewsTool', tool_name: 'g_news', display_name: 'GNews', category: 'research',
    settings_schema: [],
    operations: [] },
  { tool_class: 'CalDavCalendarTool', tool_name: 'cal_dav_calendar', display_name: 'CalDAV Calendar', category: 'communication',
    settings_schema: [{ key: 'caldav_url', label: 'URL', type: 'text', description: '', default: '', required: true, options: null, expose_to_llm: false }],
    operations: [] },
]

const allToolsRef = ref<typeof tools>(tools)
const loadingToolsRef = ref(false)

const ToolSettingsPanelStub = { name: 'ToolSettingsPanel', template: '<div class="settings-panel-stub" />' }

beforeEach(() => {
  setActivePinia(createPinia())
  routeQuery.value = {}
  allToolsRef.value = tools
  loadingToolsRef.value = false
  getGlobalSettingsMock.mockReset().mockResolvedValue({})
  replaceMock.mockReset()
})

function mountPage() {
  return mount(SettingsToolsPage, {
    global: {
      provide: { settingsTools: { allTools: allToolsRef, loadingTools: loadingToolsRef } },
      stubs: { ToolSettingsPanel: ToolSettingsPanelStub, Icon: true, AlertBanner: true },
    },
  })
}

describe('SettingsToolsPage', () => {
  it('renders the page title in list view', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Tool Settings')
  })

  it('groups tools by category with configurable tools only', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Calculator')
    expect(wrapper.text()).toContain('News API')
    expect(wrapper.text()).toContain('CalDAV Calendar')
    // Tools with empty settings_schema are not shown
    expect(wrapper.text()).not.toContain('Scratchpad')
    expect(wrapper.text()).not.toContain('GNews')
  })

  it('sorts categories case-insensitively', () => {
    allToolsRef.value = [
      { tool_class: 'A', tool_name: 'a', display_name: 'A', category: 'zebra', settings_schema: [{ key: 'k', label: 'L', type: 'text', description: '', default: '', required: false, options: null, expose_to_llm: false }], operations: [] },
      { tool_class: 'B', tool_name: 'b', display_name: 'B', category: 'general', settings_schema: [{ key: 'k', label: 'L', type: 'text', description: '', default: '', required: false, options: null, expose_to_llm: false }], operations: [] },
      { tool_class: 'C', tool_name: 'c', display_name: 'C', category: 'research', settings_schema: [{ key: 'k', label: 'L', type: 'text', description: '', default: '', required: false, options: null, expose_to_llm: false }], operations: [] },
    ]
    const wrapper = mountPage()
    const html = wrapper.html()
    const generalIdx = html.indexOf('General')
    const researchIdx = html.indexOf('Research')
    const zebraIdx = html.indexOf('Zebra')
    expect(generalIdx).toBeLessThan(researchIdx)
    expect(researchIdx).toBeLessThan(zebraIdx)
  })

  it('shows a loading message when loadingTools is true', () => {
    loadingToolsRef.value = true
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Loading')
  })

  it('shows an empty-state when no tools are configurable', () => {
    allToolsRef.value = []
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('No configurable tools')
  })

  it('selects a tool and renders the settings panel', async () => {
    const wrapper = mountPage()
    const newsRow = wrapper.findAll('div').find((d) => d.text().includes('News API') && d.classes().includes('cursor-pointer'))
    expect(newsRow).toBeDefined()
    await newsRow!.trigger('click')
    await flushPromises()
    expect(getGlobalSettingsMock).toHaveBeenCalledWith('news_api')
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-tools', query: { tool: 'news_api' } })
    expect(wrapper.find('.settings-panel-stub').exists()).toBe(true)
  })

  it('handles getGlobalSettings failure gracefully', async () => {
    getGlobalSettingsMock.mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    const row = wrapper.findAll('div').find((d) => d.text().includes('Calculator') && d.classes().includes('cursor-pointer'))
    await row!.trigger('click')
    await flushPromises()
    // The panel still renders with empty defaults
    expect(wrapper.find('.settings-panel-stub').exists()).toBe(true)
  })

  it('auto-selects the tool from the ?tool= query param on mount', async () => {
    routeQuery.value = { tool: 'calculator' }
    const wrapper = mountPage()
    await flushPromises()
    expect(getGlobalSettingsMock).toHaveBeenCalledWith('calculator')
    expect(wrapper.find('.settings-panel-stub').exists()).toBe(true)
  })

  it('goes back to the list view when back is emitted from the panel', async () => {
    routeQuery.value = { tool: 'calculator' }
    const wrapper = mountPage()
    await flushPromises()
    const panel = wrapper.findComponent({ name: 'ToolSettingsPanel' })
    await panel.vm.$emit('back')
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-tools' })
  })

  it('toggles category collapse when a category header is clicked', async () => {
    const wrapper = mountPage()
    const generalHeader = wrapper.findAll('div').find((d) => d.text().includes('General') && d.classes().includes('cursor-pointer'))
    expect(generalHeader).toBeDefined()
    await generalHeader!.trigger('click')
    // After collapsing, Calculator should no longer be visible
    expect(wrapper.text()).not.toContain('Calculator')
  })
})
