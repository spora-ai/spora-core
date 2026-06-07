/**
 * GlobalNavbar — top nav with notifications, theme toggle, apps dropdown,
 * user menu, and sign-out.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>', props: ['to'] },
}))

const userRef = ref<{ name: string; email: string; roles: string[] } | null>(null)
const logoutMock = vi.fn()
const isDarkRef = ref(false)
const unreadCountRef = ref(0)
const notificationsRef = ref<unknown[]>([])
const fetchNotificationsMock = vi.fn()

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() { return userRef.value },
    logout: logoutMock,
  }),
}))

vi.mock('@/stores/theme', () => ({
  useThemeStore: () => ({
    get isDark() { return isDarkRef.value },
    toggle: () => { isDarkRef.value = !isDarkRef.value },
  }),
}))

vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({
    get unreadCount() { return unreadCountRef.value },
    get notifications() { return notificationsRef.value },
    fetchNotifications: fetchNotificationsMock,
  }),
}))

vi.mock('@/composables/useRealtime', () => ({
  useRealtime: () => undefined,
}))

const { apiGetMock } = vi.hoisted(() => ({ apiGetMock: vi.fn() }))
vi.mock('@/api/client', () => ({
  api: { get: apiGetMock },
}))

import GlobalNavbar from '@/components/GlobalNavbar.vue'

const NotificationCenterStub = { name: 'NotificationCenter', template: '<div class="nc-stub" />' }

beforeEach(() => {
  setActivePinia(createPinia())
  userRef.value = null
  logoutMock.mockReset()
  logoutMock.mockResolvedValue(undefined)
  isDarkRef.value = false
  unreadCountRef.value = 0
  notificationsRef.value = []
  fetchNotificationsMock.mockReset()
  pushMock.mockReset()
  apiGetMock.mockReset()
})

describe('GlobalNavbar', () => {
  it('renders without throwing', () => {
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    expect(wrapper.exists()).toBe(true)
  })

  it('shows the unread count badge when unreadCount > 0', () => {
    unreadCountRef.value = 5
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    expect(wrapper.text()).toContain('5')
  })

  it('caps the badge at 99+', () => {
    unreadCountRef.value = 150
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    expect(wrapper.text()).toContain('99+')
  })

  it('hides the badge when unreadCount is 0', () => {
    unreadCountRef.value = 0
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    expect(wrapper.text()).not.toMatch(/99\+/)
  })

  it('toggles the theme when the dark-mode button is clicked', async () => {
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const btn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Switch to'))!
    expect(btn).toBeDefined()
    await btn.trigger('click')
    expect(isDarkRef.value).toBe(true)
  })

  it('fetches /apps when the apps dropdown is opened for the first time', async () => {
    apiGetMock.mockResolvedValue({ apps: [] })
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const btn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Apps')!
    await btn.trigger('click')
    await flushPromises()
    expect(apiGetMock).toHaveBeenCalledWith('/apps')
  })

  it('shows "No apps installed" when the apps response is empty', async () => {
    apiGetMock.mockResolvedValue({ apps: [] })
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const btn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Apps')!
    await btn.trigger('click')
    await flushPromises()
    expect(document.body.textContent ?? '').toContain('No apps installed')
  })

  it('clears the apps list on /apps failure', async () => {
    apiGetMock.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const btn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Apps')!
    await btn.trigger('click')
    await flushPromises()
    expect(apiGetMock).toHaveBeenCalled()
  })

  it('logs out and navigates to login on sign-out', async () => {
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const userBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Account menu')!
    await userBtn.trigger('click')
    await flushPromises()
    const signOutBtn = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes('Sign out'))! as HTMLButtonElement
    const signOut = { trigger: () => signOutBtn.click() } as never
    await signOut.trigger('click')
    await flushPromises()
    expect(logoutMock).toHaveBeenCalled()
    expect(pushMock).toHaveBeenCalledWith({ name: 'login' })
  })

  it('user menu "My Account" item navigates to account route', async () => {
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const userBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Account menu')!
    await userBtn.trigger('click')
    await flushPromises()
    const myAccountBtn = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes('My Account'))! as HTMLButtonElement
    expect(myAccountBtn).toBeDefined()
    myAccountBtn.click()
    await flushPromises()
    expect(pushMock).toHaveBeenCalledWith({ name: 'account' })
  })

  it('user menu "Profile" item navigates to profile route', async () => {
    const wrapper = mount(GlobalNavbar, {
      global: { stubs: { RouterLink: true, NotificationCenter: NotificationCenterStub } },
    })
    const userBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Account menu')!
    await userBtn.trigger('click')
    await flushPromises()
    const profileBtn = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === 'Profile')! as HTMLButtonElement
    expect(profileBtn).toBeDefined()
    profileBtn.click()
    await flushPromises()
    expect(pushMock).toHaveBeenCalledWith({ name: 'profile' })
  })
})
