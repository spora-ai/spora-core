/**
 * useRealtime — unified real-time interface using SSE with automatic fallback to polling.
 *
 * Auto-connects on creation and cleans up on component unmount.
 * When Mercure is configured it uses SSE; otherwise it falls back to polling.
 *
 * Uses a module-level singleton EventSource so the SSE connection persists
 * across route changes (no reconnect churn on every navigation).
 */
import { ref, computed, onUnmounted } from 'vue'
import { useTaskStore } from '@/stores/tasks'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/api/client'

// ── Module-level singleton ────────────────────────────────────────────────────

let globalEventSource: EventSource | null = null
let globalConnected = ref(false)

// ── Composable ────────────────────────────────────────────────────────────────

export function useRealtime() {
  const taskStore = useTaskStore()
  const notificationStore = useNotificationStore()
  const authStore = useAuthStore()

  // Reuse existing SSE connection if already open
  if (globalEventSource?.readyState === EventSource.OPEN) {
    return { connected: globalConnected }
  }

  // Clean up any stale connection before creating a new one
  if (globalEventSource) {
    globalEventSource.close()
    globalEventSource = null
  }

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

      // Support both relative (/path) and absolute (http://host/path) hubUrl
      const baseUrl = authResponse.hubUrl.startsWith('/')
        ? authResponse.hubUrl
        : authResponse.hubUrl
      const url = new URL(baseUrl, window.location.origin)

      // append() adds both topics (set() overwrites the first one)
      url.searchParams.append('topic', 'task/*')
      if (userId !== undefined) {
        url.searchParams.append('topic', `user/${userId}/notifications`)
      }

      globalEventSource = new EventSource(url.toString())

      globalEventSource.onmessage = (event: MessageEvent) => {
        const data = JSON.parse(event.data) as { topic: string; data: Record<string, unknown> }

        if (data.topic.startsWith('task/')) {
          const taskId = parseInt(data.topic.split('/')[1], 10)
          taskStore.applyTaskUpdate(taskId, data.data as Record<string, unknown>)
        } else if (data.topic.startsWith('user/')) {
          type MercurePayload = { notification: Parameters<typeof notificationStore.prependFromSSE>[0] }
          const payload = data.data as unknown as MercurePayload
          if (payload.notification) {
            notificationStore.prependFromSSE(payload.notification)
          }
        }
      }

      globalEventSource.onerror = () => {
        // Network error or hub unreachable — tear down and fall back to polling
        disconnect()
        startPollingFallback()
      }

      globalConnected.value = true
    } catch {
      // Auth endpoint returned 404 or network error — Mercure not available; use polling
      startPollingFallback()
    }
  }

  function startPollingFallback(): void {
    globalConnected.value = false

    // Stop any lingering SSE connection
    if (globalEventSource) {
      globalEventSource.close()
      globalEventSource = null
    }

    // Start the adaptive polling loop managed entirely by the store.
    taskStore.startListPolling()
  }

  function disconnect(): void {
    if (globalEventSource) {
      globalEventSource.close()
      globalEventSource = null
    }
    globalConnected.value = false
  }

  connect()

  onUnmounted(() => {
    // Only disconnect if no other consumer is using the connection.
    // Since we share the singleton, we don't auto-disconnect on unmount —
    // the connection persists across route changes.
  })

  return { connected: computed(() => globalConnected) }
}