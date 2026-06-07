<script setup lang="ts">
/**
 * ToastContainer — portal-mounted toast queue.
 *
 * Renders at <body> level, positioned bottom-right on desktop, top-center on mobile.
 * Manages the toast queue: adds, removes, auto-dismisses.
 */
import { computed } from 'vue'
import Toast from './Toast.vue'

interface ToastItem {
  id: string
  severity: 'error' | 'warning' | 'success' | 'info'
  message: string
  action?: string
  onAction?: () => void
}

defineProps<{
  toasts: readonly ToastItem[]
  onDismiss: (id: string) => void // eslint-disable-line no-unused-vars -- called via onDismiss(toast.id) in template
}>()

// globalThis.window is the browser window (Window) or undefined in SSR.
// Direct undefined comparison satisfies typescript:S7741; the SSR guard is
// defensive — this component is only ever mounted in the browser via Teleport.
const isMobile = computed(() => globalThis.window !== undefined && globalThis.window.innerWidth < 640)
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed z-[100] flex flex-col gap-2"
      :class="isMobile
        ? 'top-4 left-1/2 -translate-x-1/2 right-4'
        : 'bottom-4 right-4'"
      aria-live="assertive"
      aria-label="Notifications"
    >
      <TransitionGroup name="toast">
        <Toast
          v-for="toast in toasts"
          :key="toast.id"
          :id="toast.id"
          :severity="toast.severity"
          :message="toast.message"
          :action="toast.action"
          :on-action="toast.onAction"
          :on-dismiss="() => onDismiss(toast.id)"
        />
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active {
  transition: all 0.25s ease-out;
}
.toast-leave-active {
  transition: all 0.2s ease-in;
}
.toast-enter-from {
  opacity: 0;
  transform: translateX(100%);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>