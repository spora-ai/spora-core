/**
 * SettingsOverviewPage — global settings overview with LLM + Tools sections.
 *
 * The page reads from `inject('settingsTools')` and the llm store. The test
 * provides both and asserts the section headers render.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ configs: [] }),
}))

import SettingsOverviewPage from '@/pages/settings/SettingsOverviewPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  pushMock.mockReset()
})

describe('SettingsOverviewPage', () => {
  it('renders the Global Settings heading and Tools + LLM sections', () => {
    const allTools = ref<Array<{ tool_name: string; settings_schema: unknown[] }>>([])
    const loadingTools = ref(false)
    const wrapper = mount(SettingsOverviewPage, {
      global: { provide: { settingsTools: { allTools, loadingTools } } },
    })
    expect(wrapper.text()).toContain('Global Settings')
    expect(wrapper.text()).toMatch(/tools/i)
    expect(wrapper.text()).toMatch(/llm/i)
  })

  it('shows a "Loading…" indicator while tools are loading', () => {
    const allTools = ref<Array<{ tool_name: string; settings_schema: unknown[] }>>([])
    const loadingTools = ref(true)
    const wrapper = mount(SettingsOverviewPage, {
      global: { provide: { settingsTools: { allTools, loadingTools } } },
    })
    expect(wrapper.text()).toContain('Loading')
  })

  it('navigates to settings-tools when "View all" is clicked', async () => {
    const allTools = ref<Array<{ tool_name: string; settings_schema: unknown[] }>>([])
    const loadingTools = ref(false)
    const wrapper = mount(SettingsOverviewPage, {
      global: { provide: { settingsTools: { allTools, loadingTools } } },
    })
    const viewAllButton = wrapper.findAll('button').find((b) => b.text().includes('View all'))
    expect(viewAllButton).toBeDefined()
    await viewAllButton!.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-tools' })
  })
})
