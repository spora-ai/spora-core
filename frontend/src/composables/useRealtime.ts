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
import { useAgentStore } from '@/stores/agent'
import { api } from '@/api/client'

// ── Module-level singleton ────────────────────────────────────────────────────

let globalEventSource: EventSource | null = null
let globalConnected = ref(false)

export { globalConnected }

// ── Composable ────────────────────────────────────────────────────────────────

export function useRealtime() {
  const taskStore = useTaskStore()
  const notificationStore = useNotificationStore()
  const authStore = useAuthStore()
  const agentStore = useAgentStore()

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
      // Wait for auth to be initialized before connecting
      if (!authStore.initialized) {
        // Auth not ready yet — poll until it is, then connect
        await new Promise<void>(resolve => {
          const stop = setInterval(() => {
            if (authStore.initialized) {
              clearInterval(stop)
              resolve()
            }
          }, 50)
        })
      }

      // Not logged in — skip SSE entirely
      if (authStore.user === null) {
        startPollingFallback()
        return
      }

      // First check if SSE is configured and active
      const statusResponse = await api.get<{ active: boolean; hubUrl?: string }>('/sse/status')
      if (!statusResponse.active || !statusResponse.hubUrl) {
        startPollingFallback()
        return
      }

      // Fetch auth token and subscribe to user-specific notification topic
      const authResponse = await api.get<{ hubUrl: string; token: string }>('/sse/auth')
      const userId = authStore.user!.id

      // Support both relative (/path) and absolute (http://host/path) hubUrl
      const baseUrl = authResponse.hubUrl.startsWith('/')
        ? authResponse.hubUrl
        : authResponse.hubUrl
      const url = new URL(baseUrl, window.location.origin)

      // append() adds both topics (set() overwrites the first one)
      url.searchParams.append('topic', `user/${userId}/tasks`)
      url.searchParams.append('topic', `user/${userId}/notifications`)

      globalEventSource = new EventSource(url.toString())

      globalEventSource.onmessage = (event: MessageEvent) => {
        const data = JSON.parse(event.data) as { topic: string; data: Record<string, unknown> }

        if (data.topic.startsWith('user/')) {
          // Topic format: user/{userId}/tasks or user/{userId}/notifications
          // The task id is inside the payload — either `task_id` (explicit publish)
          // or `id` (from taskResource()). Both are supported.
          type MercureTaskPayload = { task_id?: number; id?: number }
          const innerData = data.data as Record<string, unknown>
          const taskId = (innerData as unknown as MercureTaskPayload).task_id
            ?? (innerData as { id?: number }).id
          if (taskId !== undefined) {
            // Task event on user/{userId}/tasks — use targeted update
            taskStore.applyTaskUpdate(taskId, innerData)
            // Also update agentStore task list so AgentPage can skip polling
            agentStore.applySseTaskEvent(innerData)
            taskStore.applySseEventToTasks(innerData)
          } else {
            // Notification event on user/{userId}/notifications
            type MercureNotificationPayload = { notification: Parameters<typeof notificationStore.prependFromSSE>[0] }
            const payload = data.data as unknown as MercureNotificationPayload
            if (payload.notification) {
              notificationStore.prependFromSSE(payload.notification)
            }
          }
        }
      }

      globalEventSource.onerror = () => {
        // Network error or hub unreachable — tear down and fall back to polling
        disconnect()
        startPollingFallback()
      }

      globalConnected.value = true
      taskStore.stopDashboardPolling()
      notificationStore.stopNotificationPolling()
      notificationStore.fetchNotifications()
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
    taskStore.startDashboardPolling()
    notificationStore.startNotificationPolling()
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