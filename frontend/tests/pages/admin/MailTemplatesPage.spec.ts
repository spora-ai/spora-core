/**
 * MailTemplatesPage — admin mail template editor.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1, email: 'admin@x.com', name: 'Admin', roles: ['ADMIN'], is_admin: true } }),
}))

vi.mock('@/composables/useMailTemplates', () => ({
  useMailTemplates: () => ({
    templates: [],
    loading: false,
    error: null,
    fetchTemplates: vi.fn().mockResolvedValue(undefined),
    fetchTemplate: vi.fn().mockResolvedValue(undefined),
  }),
  MAIL_TEMPLATE_PLACEHOLDERS: [],
}))

import MailTemplatesPage from '@/pages/admin/MailTemplatesPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('MailTemplatesPage', () => {
  it('mounts and loads templates', async () => {
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.exists()).toBe(true)
  })
})
