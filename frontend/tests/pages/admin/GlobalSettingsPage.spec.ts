/**
 * GlobalSettingsPage — global app settings (SMTP, etc.).
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn() }),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), put: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import { api } from '@/api/client'

const getMock = api.get as ReturnType<typeof vi.fn>
import GlobalSettingsPage from '@/pages/admin/GlobalSettingsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  getMock.mockResolvedValue({ settings: {} })
})

describe('GlobalSettingsPage', () => {
  it('mounts without throwing', () => {
    const wrapper = mount(GlobalSettingsPage)
    expect(wrapper.exists()).toBe(true)
  })

  it('loads the global settings on mount', async () => {
    mount(GlobalSettingsPage)
    await flushPromises()
    expect(getMock).toHaveBeenCalled()
  })
})
