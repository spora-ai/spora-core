/**
 * GlobalSettingsLayout — host layout for the /settings tree: navbar, sidebar, RouterView.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const { apiGetMock, ensureMock } = vi.hoisted(() => ({
  apiGetMock: vi.fn(),
  ensureMock: vi.fn(),
}))

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ ensure: ensureMock }),
}))

vi.mock('@/api/client', () => ({
  api: { get: apiGetMock },
  ApiError: class ApiError extends Error { constructor(public m: string) { super(m) } },
}))

const RouterViewStub = { name: 'RouterView', template: '<div class="router-view-stub"><slot /></div>' }
const GlobalNavbarStub = { name: 'GlobalNavbar', template: '<div class="navbar-stub" />' }
const SettingsSidebarStub = {
  name: 'SettingsSidebar',
  props: ['allTools', 'loadingTools', 'mobileOpen'],
  emits: ['close'],
  template: '<div class="sidebar-stub" :data-mobile="mobileOpen" @click="$emit(\'close\')" />',
}
const IconStub = { name: 'Icon', template: '<i />' }

import GlobalSettingsLayout from '@/pages/settings/GlobalSettingsLayout.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ tools: [] })
  ensureMock.mockClear().mockResolvedValue(undefined)
})

function mountLayout() {
  return mount(GlobalSettingsLayout, {
    global: {
      stubs: {
        RouterView: RouterViewStub,
        GlobalNavbar: GlobalNavbarStub,
        SettingsSidebar: SettingsSidebarStub,
        Icon: IconStub,
      },
    },
  })
}

describe('GlobalSettingsLayout', () => {
  it('renders GlobalNavbar, desktop SettingsSidebar and RouterView', () => {
    const wrapper = mountLayout()
    expect(wrapper.find('.navbar-stub').exists()).toBe(true)
    expect(wrapper.findAll('.sidebar-stub').length).toBeGreaterThan(0)
    expect(wrapper.find('.router-view-stub').exists()).toBe(true)
  })

  it('loads tools and llm data on mount', async () => {
    mountLayout()
    await flushPromises()
    expect(apiGetMock).toHaveBeenCalledWith('/tools')
    expect(ensureMock).toHaveBeenCalled()
  })

  it('passes loaded tools down to SettingsSidebar', async () => {
    const tools = [{ tool_class: 'A', tool_name: 'a', display_name: 'A', category: '', settings_schema: [], operations: [] }]
    apiGetMock.mockResolvedValue({ tools })
    const wrapper = mountLayout()
    await flushPromises()
    const sidebars = wrapper.findAllComponents({ name: 'SettingsSidebar' })
    expect(sidebars.length).toBeGreaterThan(0)
    const props = sidebars[0].props() as { allTools: unknown[] }
    expect(props.allTools).toHaveLength(1)
  })

  it('handles /tools fetch failure gracefully (does not throw)', async () => {
    apiGetMock.mockRejectedValue(new Error('boom'))
    const wrapper = mountLayout()
    await flushPromises()
    expect(wrapper.exists()).toBe(true)
  })

  it('opens the mobile sidebar when the menu button is clicked', async () => {
    const wrapper = mountLayout()
    const menuBtn = wrapper.find('button[title="Show settings menu"]')
    expect(menuBtn.exists()).toBe(true)
    await menuBtn.trigger('click')
    const sidebars = wrapper.findAllComponents({ name: 'SettingsSidebar' })
    const mobileSidebar = sidebars.find((s) => (s.props() as { mobileOpen?: unknown }).mobileOpen !== undefined)
    expect(mobileSidebar).toBeDefined()
  })

  it('closes the mobile sidebar when the overlay is clicked', async () => {
    const wrapper = mountLayout()
    const menuBtn = wrapper.find('button[title="Show settings menu"]')
    await menuBtn.trigger('click')
    const sidebars = wrapper.findAllComponents({ name: 'SettingsSidebar' })
    const mobileSidebar = sidebars.find((s) => (s.props() as { mobileOpen?: unknown }).mobileOpen !== undefined)
    expect(mobileSidebar).toBeDefined()
    await mobileSidebar!.vm.$emit('close')
    await wrapper.vm.$nextTick()
    const sidebarsAfter = wrapper.findAllComponents({ name: 'SettingsSidebar' })
    const stillOpen = sidebarsAfter.find((s) => (s.props() as { mobileOpen?: unknown }).mobileOpen !== undefined)
    expect(stillOpen).toBeUndefined()
  })
})
