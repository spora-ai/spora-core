import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '@/api/client'
import { useNotificationStore } from '@/stores/notifications'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

const sampleNotification = {
  id: 1,
  type: 'task_completed' as const,
  title: 'Done',
  body: 'Task finished',
  data: null,
  read_at: null,
  created_at: '2026-01-01T00:00:00Z',
}

describe('useNotificationStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchNotifications', () => {
    it('fetches notifications and stores them', async () => {
      mockApi.get.mockResolvedValueOnce({ notifications: [sampleNotification] })

      const store = useNotificationStore()
      await store.fetchNotifications()

      expect(mockApi.get).toHaveBeenCalledWith('/notifications')
      expect(store.notifications).toEqual([sampleNotification])
    })
  })

  describe('unreadCount', () => {
    it('counts notifications without read_at', () => {
      const store = useNotificationStore()
      store.notifications = [
        { ...sampleNotification, id: 1, read_at: null },
        { ...sampleNotification, id: 2, read_at: '2026-01-02T00:00:00Z' },
        { ...sampleNotification, id: 3, read_at: null },
      ]
      expect(store.unreadCount).toBe(2)
    })

    it('is 0 when empty', () => {
      const store = useNotificationStore()
      expect(store.unreadCount).toBe(0)
    })
  })

  describe('markRead', () => {
    it('posts read and updates read_at', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      store.notifications = [{ ...sampleNotification, id: 1, read_at: null }]

      await store.markRead(1)

      expect(mockApi.post).toHaveBeenCalledWith('/notifications/1/read')
      expect(store.notifications[0].read_at).not.toBeNull()
    })

    it('does not overwrite read_at when already read', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      const original = '2026-01-01T00:00:00Z'
      store.notifications = [{ ...sampleNotification, id: 1, read_at: original }]

      await store.markRead(1)

      expect(store.notifications[0].read_at).toBe(original)
    })

    it('is a no-op when id not found', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      store.notifications = [{ ...sampleNotification, id: 1, read_at: null }]

      await store.markRead(999)

      expect(store.notifications[0].read_at).toBeNull()
    })
  })

  describe('markAllRead', () => {
    it('posts read-all and marks all unread as read', async () => {
      mockApi.post.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      const existing = '2026-01-01T00:00:00Z'
      store.notifications = [
        { ...sampleNotification, id: 1, read_at: null },
        { ...sampleNotification, id: 2, read_at: existing },
        { ...sampleNotification, id: 3, read_at: null },
      ]

      await store.markAllRead()

      expect(mockApi.post).toHaveBeenCalledWith('/notifications/read-all')
      expect(store.notifications[0].read_at).not.toBeNull()
      expect(store.notifications[1].read_at).toBe(existing)
      expect(store.notifications[2].read_at).not.toBeNull()
    })
  })

  describe('deleteNotification', () => {
    it('deletes from API and removes from list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      store.notifications = [
        { ...sampleNotification, id: 1 },
        { ...sampleNotification, id: 2 },
      ]

      await store.deleteNotification(1)

      expect(mockApi.delete).toHaveBeenCalledWith('/notifications/1')
      expect(store.notifications.map(n => n.id)).toEqual([2])
    })
  })

  describe('deleteAll', () => {
    it('deletes all from API and clears list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useNotificationStore()
      store.notifications = [{ ...sampleNotification }]

      await store.deleteAll()

      expect(mockApi.delete).toHaveBeenCalledWith('/notifications')
      expect(store.notifications).toEqual([])
    })
  })

  describe('prependFromSSE', () => {
    it('prepends a new notification not already present', () => {
      const store = useNotificationStore()
      store.notifications = [{ ...sampleNotification, id: 1, created_at: '2026-01-01T00:00:00Z' }]

      const incoming = { ...sampleNotification, id: 2, created_at: '2026-01-02T00:00:00Z' }
      store.prependFromSSE(incoming)

      expect(store.notifications).toHaveLength(2)
      expect(store.notifications[0].id).toBe(2)
    })

    it('skips a notification with a duplicate id', () => {
      const store = useNotificationStore()
      store.notifications = [{ ...sampleNotification, id: 1 }]

      store.prependFromSSE({ ...sampleNotification, id: 1 })

      expect(store.notifications).toHaveLength(1)
    })

    it('sorts after prepending so newest comes first', () => {
      const store = useNotificationStore()
      store.notifications = [
        { ...sampleNotification, id: 1, created_at: '2026-01-02T00:00:00Z' },
      ]

      // Incoming notification is OLDER than existing — after sort it should be last
      store.prependFromSSE({ ...sampleNotification, id: 2, created_at: '2026-01-01T00:00:00Z' })

      expect(store.notifications[0].id).toBe(1)
      expect(store.notifications[1].id).toBe(2)
    })
  })

  describe('polling', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('startNotificationPolling fires fetchNotifications after the interval', async () => {
      mockApi.get.mockResolvedValue({ notifications: [] })

      const store = useNotificationStore()
      store.startNotificationPolling()

      await vi.advanceTimersByTimeAsync(60_000)

      expect(mockApi.get).toHaveBeenCalledWith('/notifications')
    })

    it('stopNotificationPolling clears the timer (no further calls)', async () => {
      mockApi.get.mockResolvedValue({ notifications: [] })

      const store = useNotificationStore()
      store.startNotificationPolling()
      store.stopNotificationPolling()

      await vi.advanceTimersByTimeAsync(120_000)

      expect(mockApi.get).not.toHaveBeenCalled()
    })

    it('stopNotificationPolling is safe to call when not polling', () => {
      const store = useNotificationStore()
      expect(() => store.stopNotificationPolling()).not.toThrow()
    })

    it('starting twice cancels the previous timer', async () => {
      mockApi.get.mockResolvedValue({ notifications: [] })

      const store = useNotificationStore()
      store.startNotificationPolling()
      store.startNotificationPolling()

      await vi.advanceTimersByTimeAsync(60_000)

      // Only one tick should have produced an API call (the second timer)
      expect(mockApi.get).toHaveBeenCalledTimes(1)
    })

    it('continues polling after a fetch error', async () => {
      mockApi.get
        .mockRejectedValueOnce(new Error('boom'))
        .mockResolvedValueOnce({ notifications: [] })

      const store = useNotificationStore()
      store.startNotificationPolling()

      await vi.advanceTimersByTimeAsync(60_000)
      await vi.advanceTimersByTimeAsync(60_000)

      expect(mockApi.get).toHaveBeenCalledTimes(2)
    })
  })
})
