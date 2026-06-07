/**
 * SettingsSidebar — collapsible submenu for Global Settings.
 *
 * Mocks vue-router (useRoute/useRouter), the auth store, the LLM configs
 * store, and the sub-component SettingsNavGroup to drive the render.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const routeRef = ref<{ name?: string; query?: Record<string, string> }>({ name: 'settings-overview', query: {} })
const pushMock = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => routeRef.value,
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

const userRef = ref<{ roles: string[] } | null>({ roles: ['USER'] })

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ get user() { return userRef.value } }),
}))

const configsRef = ref<Array<{ id: number; name: string }>>([])
const loadingConfigsRef = ref(false)

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({
    get personalConfigs() { return configsRef.value },
    get loadingConfigs() { return loadingConfigsRef.value },
  }),
}))

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span data-testid="chevron" />' },
  X: { template: '<span data-testid="x" />' },
}))

import SettingsSidebar from '@/components/settings/SettingsSidebar.vue'

const tools = [
  {
    tool_class: 'X',
    tool_name: 'web_search',
    display_name: 'Web Search',
    settings_schema: [{ key: 'k', label: 'K', type: 'text', default: null, required: false, scope: 'global', options: null, expose_to_llm: false, description: '' }],
  },
  {
    tool_class: 'X',
    tool_name: 'static',
    display_name: 'Static',
    settings_schema: [],
  },
]

beforeEach(() => {
  setActivePinia(createPinia())
  routeRef.value = { name: 'settings-overview', query: {} }
  pushMock.mockReset()
  configsRef.value = []
  loadingConfigsRef.value = false
  userRef.value = { roles: ['USER'] }
})

describe('SettingsSidebar', () => {
  it('renders the Settings heading', () => {
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    expect(wrapper.text()).toContain('Settings')
  })

  it('renders the Overview button as active when on the overview route', () => {
    routeRef.value = { name: 'settings-overview', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const overviewBtn = wrapper.findAll('button').find((b) => b.text() === 'Overview')!
    expect(overviewBtn.classes()).toContain('bg-primary')
  })

  it('clicking Overview pushes the settings-overview route and emits close', async () => {
    routeRef.value = { name: 'settings-tools', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const overviewBtn = wrapper.findAll('button').find((b) => b.text() === 'Overview')!
    await overviewBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-overview' })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('does not show the admin Administration group for non-admin users', () => {
    userRef.value = { roles: ['USER'] }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    expect(wrapper.text()).not.toContain('Administration')
  })

  it('shows the admin Administration group with all 4 links for admin users', () => {
    userRef.value = { roles: ['ADMIN'] }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    expect(wrapper.text()).toContain('Administration')
    expect(wrapper.text()).toContain('Users')
    expect(wrapper.text()).toContain('LLM Drivers')
    expect(wrapper.text()).toContain('Tool Defaults')
    expect(wrapper.text()).toContain('Mail Templates')
  })

  it('emits close when an admin link is clicked', async () => {
    userRef.value = { roles: ['ADMIN'] }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const usersBtn = wrapper.findAll('button').find((b) => b.text() === 'Users')!
    await usersBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-admin-users' })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('filters out tools with no settings_schema in the Tools submenu', () => {
    routeRef.value = { name: 'settings-tools', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: tools, loadingTools: false },
    })
    expect(wrapper.text()).toContain('Web Search')
    expect(wrapper.text()).not.toContain('Static')
  })

  it('shows the "Loading…" placeholder while loadingTools is true', () => {
    routeRef.value = { name: 'settings-tools', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: true },
    })
    expect(wrapper.text()).toContain('Loading…')
  })

  it('shows "No configurable tools." when no tool has settings_schema', () => {
    routeRef.value = { name: 'settings-tools', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [tools[1]], loadingTools: false },
    })
    expect(wrapper.text()).toContain('No configurable tools.')
  })

  it('clicking a tool name pushes the route with the tool query param', async () => {
    routeRef.value = { name: 'settings-tools', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: tools, loadingTools: false },
    })
    const webSearchBtn = wrapper.findAll('button').find((b) => b.text() === 'Web Search')!
    await webSearchBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-tools', query: { tool: 'web_search' } })
  })

  it('renders the LLM submenu items when configs exist', () => {
    routeRef.value = { name: 'settings-llm', query: {} }
    configsRef.value = [{ id: 1, name: 'My config' }]
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    expect(wrapper.text()).toContain('My config')
    expect(wrapper.text()).toContain('+ Add New')
  })

  it('clicking "+ Add New" pushes the create query', async () => {
    routeRef.value = { name: 'settings-llm', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const addBtn = wrapper.findAll('button').find((b) => b.text() === '+ Add New')!
    await addBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-llm', query: { create: '1' } })
  })

  it('emits close when the mobile Close (X) button is clicked', async () => {
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false, mobileOpen: true },
    })
    const closeBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Close')!
    await closeBtn.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('clicks the Tools menu item and pushes the settings-tools route', async () => {
    routeRef.value = { name: 'settings-overview', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const toolsBtn = wrapper.findAll('button').find((b) => b.text() === 'Tools')!
    await toolsBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-tools' })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('clicks a personal LLM config and pushes the config route', async () => {
    routeRef.value = { name: 'settings-llm', query: {} }
    configsRef.value = [{ id: 1, name: 'My config' }]
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const configBtn = wrapper.findAll('button').find((b) => b.text() === 'My config')!
    await configBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-llm', query: { config: '1' } })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('clicks the LLM menu item and pushes the settings-llm route', async () => {
    routeRef.value = { name: 'settings-overview', query: {} }
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false },
    })
    const llmBtn = wrapper.findAll('button').find((b) => b.text() === 'LLM')!
    await llmBtn.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'settings-llm' })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows the mobile backdrop when mobileOpen is true and emits close on click', async () => {
    const wrapper = mount(SettingsSidebar, {
      props: { allTools: [], loadingTools: false, mobileOpen: true },
    })
    const backdrop = wrapper.find('.bg-black\\/50')
    expect(backdrop.exists()).toBe(true)
    await backdrop.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
