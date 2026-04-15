/**
 * useRealtime — unified real-time interface using SSE with automatic fallback to polling.
 *
 * Auto-connects on creation and cleans up on component unmount.
 * When Mercure is configured it uses SSE; otherwise it falls back to polling.
 */
import { ref, onUnmounted } from 'vue'
import { useTaskStore } from '@/stores/tasks'
import { useNotificationStore } from '@/stores/notifications'
import { api } from '@/api/client'

export function useRealtime() {
  const taskStore = useTaskStore()
  const notificationStore = useNotificationStore()

  const connected = ref(false)
  let eventSource: EventSource | null = null
  let pollInterval: ReturnType<typeof setInterval> | null = null
  let reconnectTimeout: ReturnType<typeof setTimeout> | null = null
  let reconnectDelay = 1000 // reserved for exponential backoff on reconnect

  async function connect(): Promise<void> {
    try {
      const result = await api.get<{ hubUrl: string; token: string }>('/sse/auth')

      const url = new URL(result.hubUrl)
      url.searchParams.set('topic', 'task/*')
      url.searchParams.set('topic', 'user/*')

      eventSource = new EventSource(url.toString())

      eventSource.onmessage = (event: MessageEvent) => {
        const data = JSON.parse(event.data) as { topic: string; data: Record<string, unknown> }

        if (data.topic.startsWith('task/')) {
          const taskId = parseInt(data.topic.split('/')[1], 10)
          taskStore.applyTaskUpdate(taskId, data.data as Record<string, unknown>)
        } else if (data.topic.startsWith('user/')) {
          notificationStore.prependFromSSE(data.data as unknown as Parameters<typeof notificationStore.prependFromSSE>[0])
        }
        reconnectDelay = 1000 // reset backoff on successful message (reserved for future use)
        void reconnectDelay
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

    // Poll task list every 3s when active, 10s when idle
    pollInterval = setInterval(() => {
      taskStore.startListPolling()
    }, 30_000)
  }

  function disconnect(): void {
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }
    if (pollInterval !== null) {
      clearInterval(pollInterval)
      pollInterval = null
    }
    if (reconnectTimeout !== null) {
      clearTimeout(reconnectTimeout)
      reconnectTimeout = null
    }
    connected.value = false
  }

  connect()

  onUnmounted(() => disconnect())

  return { connected }
}