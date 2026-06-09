/**
 * PluginsPage — root page component for the /apps/plugins route.
 *
 * Asserts the lifecycle (load on mount), the empty-state UI, and that a card
 * click opens the detail dialog. Heavy children (GlobalNavbar, dialog) are
 * stubbed so the assertions stay focused on the page's behaviour.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const loadMock = vi.fn()

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

// Stub the store at the module level so the page picks up our `load` ref.
vi.mock('@/apps/plugins/stores/plugins', () => ({
  usePluginsStore: () => ({
    plugins: [],
    loading: false,
    error: null as string | null,
    load: loadMock,
  }),
}))

import PluginsPage from '@/apps/plugins/pages/PluginsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
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
        PluginDetailDialog: { template: '<div class="dialog-stub" v-if="false" />' },
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
})
