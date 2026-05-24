import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/api/client'

export interface Notification {
  id: number
  type: 'task_completed' | 'task_failed' | 'pending_approval' | 'scheduled_run_completed'
  title: string
  body: string | null
  data: Record<string, unknown> | null
  read_at: string | null
  created_at: string
}

let pollTimer: ReturnType<typeof setTimeout> | null = null

export const useNotificationStore = defineStore('notifications', () => {
  const notifications = ref<Notification[]>([])
  const unreadCount = computed(() => notifications.value.filter(n => n.read_at === null).length)

  async function fetchNotifications(): Promise<void> {
    const result = await api.get<{ notifications: Notification[] }>('/notifications')
    notifications.value = result.notifications
  }

  async function markRead(id: number): Promise<void> {
    await api.post(`/notifications/${id}/read`)
    const n = notifications.value.find(n => n.id === id)
    if (n && n.read_at === null) {
      n.read_at = new Date().toISOString()
    }
  }

  async function markAllRead(): Promise<void> {
    await api.post('/notifications/read-all')
    for (const n of notifications.value) {
      if (n.read_at === null) {
        n.read_at = new Date().toISOString()
      }
    }
  }

  async function deleteNotification(id: number): Promise<void> {
    await api.delete(`/notifications/${id}`)
    notifications.value = notifications.value.filter(n => n.id !== id)
  }

  async function deleteAll(): Promise<void> {
    await api.delete('/notifications')
    notifications.value = []
  }

  /**
   * Called by useRealtime when a SSE notification event arrives.
   * Checks if the notification is already in the list (by id); if not, prepends and sorts.
   */
  function prependFromSSE(notification: Notification): void {
    if (notifications.value.some(n => n.id === notification.id)) return
    notifications.value.unshift(notification)
    notifications.value.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
  }

  function startNotificationPolling(): void {
    stopNotificationPolling()
    const tick = async () => {
      try {
        await fetchNotifications()
      } catch {
        // Network or API error — keep polling, don't crash
      } finally {
        pollTimer = setTimeout(tick, 60_000) // every 60s
      }
    }
    pollTimer = setTimeout(tick, 60_000)
  }

  function stopNotificationPolling(): void {
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  return {
    notifications,
    unreadCount,
    fetchNotifications,
    markRead,
    markAllRead,
    deleteNotification,
    deleteAll,
    prependFromSSE,
    startNotificationPolling,
    stopNotificationPolling,
  }
})