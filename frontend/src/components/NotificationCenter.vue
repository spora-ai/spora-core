<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'
import type { Notification } from '@/stores/notifications'
import Icon from '@/components/ui/Icon.vue'

const router = useRouter()
const store = useNotificationStore()

const open = ref(false)

function openPanel() {
  open.value = true
  store.fetchNotifications()
}

function closePanel() {
  open.value = false
}

function notificationIconName(type: Notification['type']): string {
  switch (type) {
    case 'task_completed': return 'check'
    case 'task_failed': return 'x'
    case 'pending_approval': return 'hourglass'
    case 'scheduled_run_completed': return 'clock'
    default: return 'bell'
  }
}

function notificationIconColor(type: Notification['type']): string {
  switch (type) {
    case 'task_completed': return 'text-green-600'
    case 'task_failed': return 'text-red-600'
    case 'pending_approval': return 'text-yellow-600'
    case 'scheduled_run_completed': return 'text-blue-600'
    default: return 'text-muted-foreground'
  }
}

function formatRelativeTime(dateStr: string): string {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffSec = Math.floor(diffMs / 1000)
  const diffMin = Math.floor(diffSec / 60)
  const diffHour = Math.floor(diffMin / 60)
  const diffDay = Math.floor(diffHour / 24)

  if (diffSec < 60) return 'just now'
  if (diffMin < 60) return `${diffMin}m ago`
  if (diffHour < 24) return `${diffHour}h ago`
  if (diffDay < 7) return `${diffDay}d ago`
  return date.toLocaleDateString()
}

function handleNotificationClick(notification: Notification) {
  if (!notification.read_at) {
    store.markRead(notification.id)
  }
  // Navigate to related task if data contains task_id
  if (notification.data && typeof notification.data === 'object' && 'task_id' in notification.data) {
    router.push({ name: 'task', params: { id: String((notification.data as { task_id: unknown }).task_id) } })
  }
  closePanel()
}

async function handleMarkAllRead() {
  await store.markAllRead()
}

async function handleClearAll() {
  await store.deleteAll()
}

// Expose open method for parent to trigger
defineExpose({ open: openPanel })
</script>

<template>
  <!-- Slide-in panel triggered by bell icon in navbar -->
  <Teleport to="body">
    <Transition name="notification-panel">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex"
        aria-modal="true"
        role="dialog"
      >
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/40 backdrop-blur-sm"
          @click="closePanel"
        />

        <!-- Panel -->
        <div
          class="absolute right-0 top-0 h-full w-full max-w-sm bg-background shadow-xl flex flex-col"
        >
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-border px-4 py-3 shrink-0">
            <h2 class="font-semibold text-foreground">Notifications</h2>
            <div class="flex items-center gap-3">
              <button
                v-if="store.unreadCount > 0"
                @click="handleMarkAllRead"
                class="text-xs text-primary hover:text-primary/80 font-medium"
              >
                Mark all read
              </button>
              <button
                v-if="store.notifications.length > 0"
                @click="handleClearAll"
                class="text-xs text-muted-foreground hover:text-destructive font-medium"
              >
                Clear all
              </button>
            </div>
          </div>

          <!-- Notification list -->
          <div class="flex-1 overflow-y-auto">
            <!-- Empty state -->
            <div
              v-if="store.notifications.length === 0"
              class="flex flex-col items-center justify-center h-full text-center text-muted-foreground text-sm gap-2 px-4"
            >
              <span class="text-2xl"><Icon name="bell" class="h-6 w-6" /></span>
              <span>No notifications yet</span>
            </div>

            <!-- Notification items -->
            <div v-else class="divide-y divide-border">
              <div
                v-for="notification in store.notifications"
                :key="notification.id"
                @click="handleNotificationClick(notification)"
                class="flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-muted/50 transition-colors"
                :class="{ 'bg-primary/5': notification.read_at === null }"
              >
                <!-- Unread dot -->
                <div
                  v-if="notification.read_at === null"
                  class="mt-1.5 h-2 w-2 rounded-full bg-primary shrink-0"
                />

                <!-- Icon -->
                <Icon
                    :name="notificationIconName(notification.type)"
                    :class="`shrink-0 mt-0.5 ${notificationIconColor(notification.type)}`"
                  />

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-foreground leading-tight">{{ notification.title }}</p>
                  <p
                    v-if="notification.body"
                    class="text-xs text-muted-foreground mt-0.5 line-clamp-2"
                  >
                    {{ notification.body }}
                  </p>
                  <p class="text-xs text-muted-foreground mt-1">{{ formatRelativeTime(notification.created_at) }}</p>
                </div>

                <!-- Delete -->
                <button
                  @click.stop="store.deleteNotification(notification.id)"
                  class="text-muted-foreground hover:text-destructive transition-colors p-1 shrink-0"
                  title="Delete"
                >
                  <Icon name="x" class="h-3.5 w-3.5" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.notification-panel-enter-active,
.notification-panel-leave-active {
  transition: opacity 0.2s ease;
}

.notification-panel-enter-from,
.notification-panel-leave-to {
  opacity: 0;
}

.notification-panel-enter-active .absolute.right-0,
.notification-panel-leave-active .absolute.right-0 {
  transition: transform 0.25s ease;
}

.notification-panel-enter-from .absolute.right-0 {
  transform: translateX(100%);
}

.notification-panel-leave-to .absolute.right-0 {
  transform: translateX(100%);
}
</style>