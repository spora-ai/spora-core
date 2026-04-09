import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import SettingsSidebar from '@/components/settings/SettingsSidebar.vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { LLMConfigResource } from '@/types/llmConfig'

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span data-testid="chevron" />' },
}))

const makeTool = (name: string, displayName: string) => ({
  tool_class: `Spora\\Tools\\${name}`,
  tool_name: name,
  display_name: displayName,
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
  ],
})

const makeConfig = (id: number, name: string): LLMConfigResource => ({
  id,
  name,
  driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
  driver_name: 'openai_compatible',
  driver_display_name: 'OpenAI Compatible',
  settings: {},
  is_default: false,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
})

const allTools = [makeTool('web_search', 'Web Search'), makeTool('email', 'Email')]

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/overview', name: 'settings-overview', component: { template: '<div />' } },
      { path: '/settings/tools', name: 'settings-tools', component: { template: '<div />' } },
      { path: '/settings/llm', name: 'settings-llm', component: { template: '<div />' } },
    ],
  })
}

async function mountSidebar(
  path: string,
  query: Record<string, string> = {},
  configs: LLMConfigResource[] = [],
) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const store = useLlmConfigsStore()
  store.configs = configs

  const router = makeRouter()
  await router.push({ path, query })
  await router.isReady()

  const wrapper = mount(SettingsSidebar, {
    props: { allTools, loadingTools: false },
    global: { plugins: [router, pinia] },
  })

  await flushPromises()

  return { wrapper, router, store }
}

describe('SettingsSidebar', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  describe('main nav active state', () => {
    it('highlights Overview when on settings-overview', async () => {
      const { wrapper } = await mountSidebar('/settings/overview')
      const btn = wrapper.findAll('button').find((b) => b.text().trim() === 'Overview')
      expect(btn?.classes()).toContain('bg-primary')
    })

    it('highlights Tools when on settings-tools', async () => {
      const { wrapper } = await mountSidebar('/settings/tools')
      const btn = wrapper.findAll('button').find((b) => b.text().replace(/\s+/g, ' ').trim() === 'Tools')
      expect(btn?.classes()).toContain('bg-primary')
    })

    it('highlights LLM when on settings-llm', async () => {
      const { wrapper } = await mountSidebar('/settings/llm')
      const btn = wrapper.findAll('button').find((b) => b.text().replace(/\s+/g, ' ').trim() === 'LLM')
      expect(btn?.classes()).toContain('bg-primary')
    })

    it('navigates to settings-overview when Overview clicked', async () => {
      const { wrapper, router } = await mountSidebar('/settings/tools')
      const btn = wrapper.findAll('button').find((b) => b.text().trim() === 'Overview')
      await btn!.trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('settings-overview')
    })
  })

  describe('tool submenu', () => {
    it('shows tool submenu only when on settings-tools', async () => {
      const { wrapper: onTools } = await mountSidebar('/settings/tools')
      expect(onTools.text()).toContain('Web Search')
      expect(onTools.text()).toContain('Email')

      const { wrapper: onOverview } = await mountSidebar('/settings/overview')
      expect(onOverview.text()).not.toContain('Web Search')
    })

    it('navigates with tool query param when a tool is clicked', async () => {
      const { wrapper, router } = await mountSidebar('/settings/tools')

      const btn = wrapper.findAll('button').find((b) => b.text().includes('Web Search'))
      expect(btn).toBeDefined()
      await btn!.trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('settings-tools')
      expect(router.currentRoute.value.query.tool).toBe('web_search')
    })

    it('navigates with correct tool name for each submenu item', async () => {
      const { wrapper, router } = await mountSidebar('/settings/tools')

      const btn = wrapper.findAll('button').find((b) => b.text().includes('Email'))
      await btn!.trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.query.tool).toBe('email')
    })

    it('applies active style to the tool matching route.query.tool', async () => {
      const { wrapper } = await mountSidebar('/settings/tools', { tool: 'web_search' })

      const activeBtn = wrapper.findAll('button').find((b) => b.text().includes('Web Search'))
      expect(activeBtn?.classes()).toContain('text-primary')

      const inactiveBtn = wrapper.findAll('button').find((b) => b.text().includes('Email'))
      expect(inactiveBtn?.classes()).toContain('text-muted-foreground')
    })

    it('shows loading state when loadingTools is true', async () => {
      const pinia = createPinia()
      setActivePinia(pinia)
      const router = makeRouter()
      await router.push('/settings/tools')
      await router.isReady()

      const wrapper = mount(SettingsSidebar, {
        props: { allTools: [], loadingTools: true },
        global: { plugins: [router, pinia] },
      })
      await flushPromises()

      expect(wrapper.text()).toContain('Loading')
    })

    it('shows "No configurable tools" when no tools have settings', async () => {
      const pinia = createPinia()
      setActivePinia(pinia)
      const router = makeRouter()
      await router.push('/settings/tools')
      await router.isReady()

      const wrapper = mount(SettingsSidebar, {
        props: { allTools: [], loadingTools: false },
        global: { plugins: [router, pinia] },
      })
      await flushPromises()

      expect(wrapper.text()).toContain('No configurable tools')
    })
  })

  describe('LLM submenu', () => {
    it('shows LLM submenu only when on settings-llm', async () => {
      const { wrapper: onLLM } = await mountSidebar('/settings/llm', {}, [makeConfig(1, 'My Config')])
      expect(onLLM.text()).toContain('My Config')

      const { wrapper: onOverview } = await mountSidebar('/settings/overview', {}, [makeConfig(1, 'My Config')])
      expect(onOverview.text()).not.toContain('My Config')
    })

    it('navigates with config query param when a config is clicked', async () => {
      const { wrapper, router } = await mountSidebar('/settings/llm', {}, [makeConfig(42, 'My GPT Config')])

      const btn = wrapper.findAll('button').find((b) => b.text().includes('My GPT Config'))
      expect(btn).toBeDefined()
      await btn!.trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('settings-llm')
      expect(router.currentRoute.value.query.config).toBe('42')
    })

    it('navigates with create=1 query when Add New clicked', async () => {
      const { wrapper, router } = await mountSidebar('/settings/llm', {}, [makeConfig(1, 'Existing')])

      const btn = wrapper.findAll('button').find((b) => b.text().includes('+ Add New'))
      expect(btn).toBeDefined()
      await btn!.trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.query.create).toBe('1')
    })

    it('applies active style to config matching route.query.config', async () => {
      const { wrapper } = await mountSidebar('/settings/llm', { config: '42' }, [
        makeConfig(42, 'Active Config'),
        makeConfig(99, 'Other Config'),
      ])

      const activeBtn = wrapper.findAll('button').find((b) => b.text().includes('Active Config'))
      expect(activeBtn?.classes()).toContain('text-primary')

      const inactiveBtn = wrapper.findAll('button').find((b) => b.text().includes('Other Config'))
      expect(inactiveBtn?.classes()).toContain('text-muted-foreground')
    })

    it('shows + Add New button even when no configs exist', async () => {
      const { wrapper } = await mountSidebar('/settings/llm', {}, [])
      const btn = wrapper.findAll('button').find((b) => b.text().includes('+ Add New'))
      expect(btn).toBeDefined()
    })
  })
})
