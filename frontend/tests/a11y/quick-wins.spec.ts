/**
 * a11y quick-wins — smoke tests for the SonarQube S6819 / S5255 fixes.
 *
 * Asserts that:
 * - RegisterPage uses a native <output> element for the resend status
 *   (replaces role="status" per SonarQube S6819).
 * - NotificationCenter uses a native <dialog> element (replaces
 *   role="dialog" per SonarQube S6819).
 * - GlobalNavbar icon-only buttons expose aria-label
 *   (SonarQube S5255).
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { nextTick } from 'vue'

// Mocks
const registerMock = vi.fn()
const resendMock = vi.fn()
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ register: registerMock, resendVerification: resendMock }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

const fetchNotificationsMock = vi.fn().mockResolvedValue([])
vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({
    notifications: [],
    unreadCount: 0,
    fetchNotifications: fetchNotificationsMock,
    markAllRead: vi.fn(),
    deleteAll: vi.fn(),
    deleteNotification: vi.fn(),
    markRead: vi.fn(),
  }),
}))

const routerPushMock = vi.fn()
const routerReplaceMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: routerPushMock, replace: routerReplaceMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

import RegisterPage from '@/pages/RegisterPage.vue'
import NotificationCenter from '@/components/NotificationCenter.vue'
import GlobalNavbar from '@/components/GlobalNavbar.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  registerMock.mockReset().mockResolvedValue(undefined)
  resendMock.mockReset().mockResolvedValue(undefined)
  fetchNotificationsMock.mockClear()
  routerPushMock.mockReset()
  routerReplaceMock.mockReset()
})

describe('a11y quick wins', () => {
  it('RegisterPage uses a native <output> for the resend status', async () => {
    const wrapper = mount(RegisterPage, { global: { stubs: { RouterLink: true } } })
    // Drive the resend success state: enter the "pending verification" view,
    // then click the resend button so resendSuccess flips to true.
    const vm = wrapper.vm as unknown as { pending: boolean; resendSuccess: boolean }
    vm.pending = true
    await nextTick()
    // Resend button is the second <button> in the pending template.
    const buttons = wrapper.findAll('button')
    // The resend button text is "Resend verification email" (initial) or
    // "Email resent" (success) — match by stable label.
    const resendBtn = buttons.find((b) => /resend/i.test(b.text()))
    expect(resendBtn).toBeDefined()
    await resendBtn!.trigger('click')
    await flushPromises()
    const output = wrapper.find('output')
    expect(output.exists()).toBe(true)
    expect(output.text()).toContain('Verification email resent!')
  })

  it('NotificationCenter uses a native <dialog> element when open', async () => {
    const wrapper = mount(NotificationCenter, {
      attachTo: document.body,
    })
    // Open the panel.
    const vm = wrapper.vm as unknown as { open: () => Promise<void> }
    await vm.open()
    await flushPromises()
    const dialog = document.querySelector('dialog')
    expect(dialog).not.toBeNull()
    wrapper.unmount()
  })

  it('GlobalNavbar exposes aria-label on icon-only icon buttons', () => {
    const wrapper = mount(GlobalNavbar, {
      global: {
        stubs: {
          RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
          NotificationCenter: { name: 'NotificationCenter', template: '<div />' },
          Icon: { name: 'Icon', template: '<svg />' },
        },
      },
    })
    const ariaLabels = wrapper.findAll('[aria-label]')
    const labels = ariaLabels.map((el) => el.attributes('aria-label'))
    // The five icon-only controls added in S5255 fix:
    expect(labels).toEqual(
      expect.arrayContaining([
        'Settings',
        'Notifications',
        'Apps',
        'Account menu',
      ]),
    )
    // The dark-mode toggle uses a bound aria-label that switches by theme;
    // assert it has the attribute at all and one of the two valid values.
    const darkToggle = ariaLabels.find((el) =>
      el.attributes('aria-label') === 'Switch to light mode'
      || el.attributes('aria-label') === 'Switch to dark mode',
    )
    expect(darkToggle).toBeDefined()
  })
})
