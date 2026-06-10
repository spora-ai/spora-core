/**
 * PluginsPage — root page component for the /apps/plugins route.
 *
 * Asserts the lifecycle (load on mount), the empty-state UI, the error banner,
 * the loading state, and the card-click → detail-dialog flow. Heavy children
 * (GlobalNavbar, dialog) are stubbed so the assertions stay focused.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref, nextTick } from 'vue'
import { setActivePinia, createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const plugins = ref<unknown[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const loadMock = vi.fn()

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

vi.mock('@/apps/plugins/stores/plugins', () => ({
  usePluginsStore: () => ({
    get plugins() { return plugins.value },
    get loading() { return loading.value },
    get error() { return error.value },
    load: loadMock,
  }),
}))

import PluginsPage from '@/apps/plugins/pages/PluginsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  plugins.value = []
  loading.value = false
  error.value = null
  loadMock.mockReset()
})

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  const wrapper = mount(PluginsPage, {
    global: {
      plugins: [router],
      stubs: {
        GlobalNavbar: { template: '<div class="navbar-stub" />' },
        PluginCard: { template: '<div class="card-stub" @click="$emit(\'select\', $attrs.plugin)" />', inheritAttrs: false },
        PluginDetailDialog: { template: '<div class="dialog-stub" />' },
        RefreshCw: { template: '<span class="refresh-stub" />' },
        Puzzle: { template: '<span class="puzzle-stub" />' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('PluginsPage', () => {
  it('calls store.load() on mount', async () => {
    await mountPage()
    expect(loadMock).toHaveBeenCalledTimes(1)
  })

  it('renders the page header', async () => {
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Plugins')
    expect(wrapper.text()).toContain('Installed plugins')
  })

  it('renders the empty-state when no plugins are installed', async () => {
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('No plugins installed')
  })

  it('renders the loading message when loading and no plugins', async () => {
    loading.value = true
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Loading…')
  })

  it('renders the error banner when the store has an error', async () => {
    error.value = 'Boom'
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Boom')
  })

  it('reloads when the refresh button is clicked', async () => {
    const wrapper = await mountPage()
    expect(loadMock).toHaveBeenCalledTimes(1)
    const button = wrapper.find('button[type="button"]')
    expect(button.exists()).toBe(true)
    await button.trigger('click')
    expect(loadMock).toHaveBeenCalledTimes(2)
  })

  it('opens the detail dialog when a PluginCard emits select', async () => {
    plugins.value = [
      {
        slug: 'minimax',
        name: 'MiniMax',
        description: '',
        icon: 'puzzle',
        version: 1,
        path: '/p',
        bundledTools: [],
        bundledDrivers: [],
        recipePaths: [],
        migrations: { declared: 1, applied: 1, filesOnDisk: 1, pending: 0, lastAppliedAt: null, status: 'up_to_date' },
      },
    ]
    const wrapper = await mountPage()
    const card = wrapper.find('.card-stub')
    expect(card.exists()).toBe(true)
    await card.trigger('click')
    await nextTick()
    expect(wrapper.find('.dialog-stub').exists()).toBe(true)
  })
})
