/**
 * NotificationCenter — slide-in notification panel.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref, computed } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

const notificationsRef = ref<Array<{ id: number; type: string; title: string; body: string; read_at: string | null; created_at: string; data: Record<string, unknown> | null }>>([])
const fetchNotificationsMock = vi.fn()
const markReadMock = vi.fn()
const markAllReadMock = vi.fn()
const deleteAllMock = vi.fn()
const deleteNotificationMock = vi.fn()

vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({
    get notifications() { return notificationsRef.value },
    get unreadCount() { return notificationsRef.value.filter((n) => n.read_at === null).length },
    fetchNotifications: fetchNotificationsMock,
    markRead: markReadMock,
    markAllRead: markAllReadMock,
    deleteAll: deleteAllMock,
    deleteNotification: deleteNotificationMock,
  }),
}))

import NotificationCenter from '@/components/NotificationCenter.vue'

const IconStub = { name: 'Icon', template: '<i />' }

beforeEach(() => {
  setActivePinia(createPinia())
  notificationsRef.value = []
  fetchNotificationsMock.mockReset()
  fetchNotificationsMock.mockResolvedValue(undefined)
  markReadMock.mockReset()
  markAllReadMock.mockReset()
  deleteAllMock.mockReset()
  deleteNotificationMock.mockReset()
  pushMock.mockReset()
})

function mountNC() {
  return mount(NotificationCenter, { global: { stubs: { Icon: IconStub } }, attachTo: document.body })
}

function findItem(title: string): HTMLElement | undefined {
  return Array.from(document.body.querySelectorAll('div'))
    .find((d) => d.classList.contains('cursor-pointer') && (d.textContent ?? '').includes(title))
}

describe('NotificationCenter', () => {
  it('renders nothing when closed', () => {
    const wrapper = mountNC()
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    wrapper.unmount()
  })

  it('fetches notifications when open() is called', async () => {
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    expect(fetchNotificationsMock).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('renders the panel with notifications when open', async () => {
    notificationsRef.value = [
      { id: 1, type: 'task_completed', title: 'Done', body: 'It finished', read_at: null, created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    expect(document.body.textContent ?? '').toContain('Done')
    wrapper.unmount()
  })

  it('renders the empty state when there are no notifications', async () => {
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    expect(document.body.textContent ?? '').toContain('No notifications')
    wrapper.unmount()
  })

  it('calls markRead and navigates when a notification is clicked', async () => {
    notificationsRef.value = [
      { id: 7, type: 'task_failed', title: 'Boom', body: 'fail', read_at: null, created_at: new Date().toISOString(), data: { task_id: 42 } },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    const item = findItem('Boom')
    expect(item).toBeDefined()
    item?.click()
    await flushPromises()
    expect(markReadMock).toHaveBeenCalledWith(7)
    expect(pushMock).toHaveBeenCalledWith({ name: 'task', params: { id: '42' } })
    wrapper.unmount()
  })

  it('does not navigate when a notification has no task_id', async () => {
    notificationsRef.value = [
      { id: 8, type: 'info', title: 'Info', body: '', read_at: null, created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    const item = findItem('Info')
    expect(item).toBeDefined()
    item?.click()
    await flushPromises()
    expect(pushMock).not.toHaveBeenCalled()
    expect(markReadMock).toHaveBeenCalledWith(8)
    wrapper.unmount()
  })

  it('skips markRead for already-read notifications', async () => {
    notificationsRef.value = [
      { id: 9, type: 'info', title: 'Read', body: '', read_at: new Date().toISOString(), created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    const item = findItem('Read')
    expect(item).toBeDefined()
    item?.click()
    await flushPromises()
    expect(markReadMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('calls markAllRead when the "Mark all read" button is clicked', async () => {
    notificationsRef.value = [
      { id: 1, type: 'task_completed', title: 't', body: '', read_at: null, created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    const btn = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes('Mark all read')) as HTMLButtonElement | undefined
    expect(btn).toBeDefined()
    btn?.click()
    await flushPromises()
    expect(markAllReadMock).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('calls deleteAll when the "Clear all" button is clicked', async () => {
    notificationsRef.value = [
      { id: 1, type: 'task_completed', title: 't', body: '', read_at: null, created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    const btn = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes('Clear all')) as HTMLButtonElement | undefined
    expect(btn).toBeDefined()
    btn?.click()
    await flushPromises()
    expect(deleteAllMock).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('formats recent timestamps as "just now"', async () => {
    notificationsRef.value = [
      { id: 1, type: 'task_completed', title: 'fresh', body: '', read_at: null, created_at: new Date().toISOString(), data: null },
    ]
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    expect(document.body.textContent ?? '').toMatch(/just now/)
    wrapper.unmount()
  })
})
