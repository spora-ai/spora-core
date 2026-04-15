/**
 * useRealtime — unified real-time interface using SSE with automatic fallback to polling.
 *
 * Auto-connects on creation and cleans up on component unmount.
 * When Mercure is configured it uses SSE; otherwise it falls back to polling.
 */
import { ref, onUnmounted } from 'vue'
import { useTaskStore } from '@/stores/tasks'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api/client'

export function useRealtime() {
  const taskStore = useTaskStore()
  const notificationStore = useNotificationStore()
  const authStore = useAuthStore()

  const connected = ref(false)
  let eventSource: EventSource | null = null

  async function connect(): Promise<void> {
    try {
      // First check if SSE is configured and active
      const statusResponse = await api.get<{ active: boolean; hubUrl?: string }>('/sse/status')
      if (!statusResponse.active || !statusResponse.hubUrl) {
        startPollingFallback()
        return
      }

      // Fetch auth token and subscribe to user-specific notification topic
      const authResponse = await api.get<{ hubUrl: string; token: string }>('/sse/auth')
      const userId = authStore.user?.id
      const url = new URL(authResponse.hubUrl)
      url.searchParams.set('topic', 'task/*')
      if (userId !== undefined) {
        url.searchParams.set('topic', `user/${userId}/notifications`)
      }

      eventSource = new EventSource(url.toString())

      eventSource.onmessage = (event: MessageEvent) => {
        const data = JSON.parse(event.data) as { topic: string; data: Record<string, unknown> }

        if (data.topic.startsWith('task/')) {
          const taskId = parseInt(data.topic.split('/')[1], 10)
          taskStore.applyTaskUpdate(taskId, data.data as Record<string, unknown>)
        } else if (data.topic.startsWith('user/')) {
          notificationStore.prependFromSSE(data.data as unknown as Parameters<typeof notificationStore.prependFromSSE>[0])
        }
      }

      eventSource.onerror = () => {
        // Network error or hub unreachable — tear down and fall back to polling
        disconnect()
        startPollingFallback()
      }

      connected.value = true
    } catch {
      // Auth endpoint returned 404 or network error — Mercure not available; use polling
      startPollingFallback()
    }
  }

  function startPollingFallback(): void {
    connected.value = false

    // Stop any lingering SSE connection
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }

    // Start the adaptive polling loop managed entirely by the store.
    // startListPolling() handles its own de-duplication and adaptive 3s/10s intervals.
    taskStore.startListPolling()
  }

  function disconnect(): void {
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }
    connected.value = false
  }

  connect()

  onUnmounted(() => disconnect())

  return { connected }
}
