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
    expect(document.body.querySelector('dialog[aria-modal="true"]')).toBeNull()
    wrapper.unmount()
  })

  it('anchors the panel to the right edge of the viewport when open', async () => {
    const wrapper = mountNC()
    wrapper.vm.open()
    await flushPromises()
    // Panel is the right-anchored slide-in. It must be present in the DOM.
    const panel = Array.from(document.body.querySelectorAll('div')).find(
      (d) => d.classList.contains('right-0') && d.classList.contains('top-0') && d.classList.contains('h-full'),
    )
    expect(panel).toBeDefined()
    // The dialog wrapper must fill the viewport — guards against the
    // user-agent <dialog> default styles (margin: auto, -moz-fit-content)
    // collapsing the wrapper to a 0×0 centered box.
    const dialog = document.body.querySelector('dialog[aria-modal="true"]') as HTMLElement | null
    expect(dialog).not.toBeNull()
    expect(dialog?.classList.contains('fixed')).toBe(true)
    expect(dialog?.classList.contains('inset-0')).toBe(true)
    // The dialog must be transparent — the user-agent <dialog> ships with
    // `background: white` which would sit under the backdrop and turn the
    // dimmed area gray. The backdrop div is the only thing that should
    // visually cover the page.
    expect(dialog?.classList.contains('bg-transparent')).toBe(true)
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
