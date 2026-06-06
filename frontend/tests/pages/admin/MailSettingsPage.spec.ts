/**
 * MailSettingsPage — admin mail config (SMTP) page.
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
import MailSettingsPage from '@/pages/admin/MailSettingsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  getMock.mockResolvedValue({ config: null, password_set: false })
})

describe('MailSettingsPage', () => {
  it('mounts without throwing', () => {
    const wrapper = mount(MailSettingsPage)
    expect(wrapper.exists()).toBe(true)
  })

  it('loads the mail config on mount', async () => {
    mount(MailSettingsPage)
    await flushPromises()
    expect(getMock).toHaveBeenCalled()
  })
})
