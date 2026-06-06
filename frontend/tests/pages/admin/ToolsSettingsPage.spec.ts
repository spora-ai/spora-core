/**
 * ToolsSettingsPage — admin tools settings (global tool config).
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), put: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

const allTools = ref<Array<{ tool_name: string; display_name: string; settings_schema: unknown[] }>>([])
const loadingTools = ref(false)
const getSettingsMock = vi.fn()
vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: () => ({ getSettings: getSettingsMock }),
}))

import { api } from '@/api/client'

const getMock = api.get as ReturnType<typeof vi.fn>
import ToolsSettingsPage from '@/pages/admin/ToolsSettingsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  getMock.mockResolvedValue({ tools: [] })
  getSettingsMock.mockReset()
  getSettingsMock.mockResolvedValue({})
})

describe('ToolsSettingsPage', () => {
  it('mounts without throwing', () => {
    const wrapper = mount(ToolsSettingsPage, {
      global: { provide: { settingsTools: { allTools, loadingTools } }, stubs: { RouterLink: true } },
    })
    expect(wrapper.exists()).toBe(true)
  })

  it('loads the tool registry on mount', async () => {
    mount(ToolsSettingsPage, {
      global: { provide: { settingsTools: { allTools, loadingTools } }, stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(getMock).toHaveBeenCalledWith('/tools')
  })
})
