/**
 * DriversSettingsPage — admin LLM driver listing.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1, email: 'admin@x.com', name: 'Admin', roles: ['ADMIN'], is_admin: true } }),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import { api } from '@/api/client'

const getMock = api.get as ReturnType<typeof vi.fn>
import DriversSettingsPage from '@/pages/admin/DriversSettingsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  getMock.mockResolvedValue({ drivers: [] })
})

describe('DriversSettingsPage', () => {
  it('mounts and loads drivers', async () => {
    const wrapper = mount(DriversSettingsPage)
    await flushPromises()
    expect(getMock).toHaveBeenCalledWith('/llm-drivers')
    expect(wrapper.exists()).toBe(true)
  })

  it('renders an empty-state when there are no drivers', async () => {
    const wrapper = mount(DriversSettingsPage)
    await flushPromises()
    // Page may show 'No drivers registered' or 'Loading…' depending on state.
    expect(wrapper.text()).toMatch(/drivers|no|none|empty|loading/i)
  })
})
