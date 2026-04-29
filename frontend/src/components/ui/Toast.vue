<script setup lang="ts">
/**
 * Toast — individual notification toast.
 *
 * Props:
 *   id         — unique identifier
 *   severity   — 'error' | 'warning' | 'success' | 'info'
 *   message    — primary text content
 *   action     — optional button label (e.g., 'Retry', 'Login')
 *   onAction   — callback when action button is clicked
 *   onDismiss  — remove this toast from the queue
 *
 * Auto-dismiss: error=never, warning=8s, success/info=4s
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'

const props = withDefaults(defineProps<{
  id: string
  severity: 'error' | 'warning' | 'success' | 'info'
  message: string
  action?: string
  onAction?: () => void
  onDismiss: () => void
}>(), {})

const AUTO_DISMISS_MS: Record<string, number | null> = {
  error: null,
  warning: 8000,
  success: 4000,
  info: 4000,
}

const progress = ref(100)
let timer: ReturnType<typeof setInterval> | null = null
let startTime: number | null = null

const isAutoDismissing = computed(() => AUTO_DISMISS_MS[props.severity] !== null)

function startTimer(): void {
  const duration = AUTO_DISMISS_MS[props.severity]
  if (duration === null) return

  startTime = Date.now()
  timer = setInterval(() => {
    const elapsed = Date.now() - (startTime ?? Date.now())
    const remaining = Math.max(0, 100 - (elapsed / duration) * 100)
    progress.value = remaining

    if (remaining <= 0) {
      stopTimer()
      props.onDismiss()
    }
  }, 50)
}

function stopTimer(): void {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

onMounted(() => {
  if (isAutoDismissing.value) {
    startTimer()
  }
})

onUnmounted(() => {
  stopTimer()
})

const severityClasses = computed(() => ({
  error: {
    bg: 'bg-destructive/10 dark:bg-destructive/20',
    border: 'border-destructive/30',
    icon: 'text-destructive',
    progress: 'bg-destructive',
  },
  warning: {
    bg: 'bg-amber-50 dark:bg-amber-950/30',
    border: 'border-amber-300 dark:border-amber-700',
    icon: 'text-amber-600 dark:text-amber-400',
    progress: 'bg-amber-400',
  },
  success: {
    bg: 'bg-emerald-50 dark:bg-emerald-950/30',
    border: 'border-emerald-300 dark:border-emerald-700',
    icon: 'text-emerald-600 dark:text-emerald-400',
    progress: 'bg-emerald-500',
  },
  info: {
    bg: 'bg-blue-50 dark:bg-blue-950/30',
    border: 'border-blue-300 dark:border-blue-700',
    icon: 'text-blue-600 dark:text-blue-400',
    progress: 'bg-blue-500',
  },
}[props.severity]))

const iconNames = {
  error: 'error-circle',
  warning: 'warning',
  success: 'check-circle',
  info: 'info',
}
</script>

<template>
  <div
    class="relative flex items-start gap-3 w-80 max-w-[calc(100vw-2rem)] rounded-xl border shadow-lg overflow-hidden backdrop-blur-sm"
    :class="[severityClasses.bg, severityClasses.border]"
    role="alert"
    aria-live="assertive"
  >
    <!-- Icon -->
    <div class="shrink-0 pl-4 pt-3" :class="severityClasses.icon">
      <Icon :name="iconNames[severity]" class="h-5 w-5" />
    </div>

    <!-- Content -->
    <div class="flex-1 py-3 pr-2 min-w-0">
      <p class="text-sm font-medium text-foreground leading-snug">{{ message }}</p>

      <button
        v-if="action"
        @click="onAction"
        class="mt-1.5 text-xs font-medium text-primary hover:text-primary/80 underline underline-offset-2 transition-colors"
      >
        {{ action }}
      </button>
    </div>

    <!-- Dismiss button -->
    <button
      @click="onDismiss"
      class="shrink-0 pr-3 pt-2 text-muted-foreground hover:text-foreground transition-colors"
      aria-label="Dismiss notification"
    >
      <Icon name="x" class="h-4 w-4" />
    </button>

    <!-- Progress bar (only for auto-dismissing toasts) -->
    <div
      v-if="isAutoDismissing"
      class="absolute bottom-0 left-0 h-0.5 w-full transition-none"
      :class="severityClasses.progress"
      :style="{ width: `${progress}%` }"
    />
  </div>
</template>