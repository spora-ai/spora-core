/**
 * useTaskChatRetry — retry-banner, countdown, and cancel/retry actions for
 * the TaskChatPage. The pure derived-state math lives in
 * `@/composables/useTaskChat`; this file wires it to the task store and the
 * router.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useTaskStore } from '@/stores/tasks'
import { useAgentStore } from '@/stores/agent'
import { ApiError } from '@/api/client'
import { useToast } from '@/composables/useToast'
import {
  RETRYABLE_ERROR_CODES,
  NON_RETRYABLE_ERROR_CODES,
  formatCountdown,
  computeRetryState,
} from '@/composables/useTaskChat'

export function useTaskChatRetry() {
  const taskStore = useTaskStore()
  const agentStore = useAgentStore()
  const router = useRouter()
  const toast = useToast()

  // User-dismissed banner flag. Reset on taskId change.
  const errorBannerDismissed = ref(false)
  const cancelling = ref(false)

  const task = computed(() => taskStore.activeTask)

  // Failure-reason banner (max steps reached, only when agent allows continuation).
  const showMaxStepsBanner = computed(() => {
    if (!task.value) return false
    if (task.value.status !== 'FAILED') return false
    if (task.value.failure_reason !== 'Max steps reached.') return false
    const agent = agentStore.currentAgent
    if (!agent) return false
    return agent.allow_continuation !== false
  })

  // Retryable error banner — shows the "Retry Now" CTA.
  const showRetryBanner = computed(() => {
    if (!task.value) return false
    if (task.value.status !== 'FAILED') return false
    if (errorBannerDismissed.value) return false
    if (task.value.error_code === null) return false
    if (!RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof RETRYABLE_ERROR_CODES[number])) return false
    if (!task.value.retry_after) {
      return (task.value.max_retries ?? 0) === 0 || !autoRetryConfigured.value
    }
    if (autoRetryConfigured.value || retriesExhausted.value) return false
    if ((task.value.max_retries ?? 0) === 0) return true
    return false
  })

  // Non-retryable error banner (NO_LLM_CONFIGURATION, UNKNOWN, ...).
  const showNonRetryableErrorBanner = computed(() => {
    if (!task.value) return false
    if (task.value.status !== 'FAILED') return false
    if (errorBannerDismissed.value) return false
    if (task.value.error_code === null) return false
    if (RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof RETRYABLE_ERROR_CODES[number])) return false
    if (!NON_RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof NON_RETRYABLE_ERROR_CODES[number])) return false
    return true
  })

  // For UNKNOWN errors, show raw failure_reason since error_message is generic.
  const nonRetryableErrorMessage = computed(() => {
    if (!task.value) return null
    if (task.value.error_code === 'UNKNOWN') {
      return task.value.failure_reason || task.value.error_message
    }
    return task.value.error_message
  })

  // Countdown for auto-retry (retry_after set but not yet elapsed).
  const showCountdown = computed(() =>
    task.value?.status === 'FAILED' && task.value.retry_after !== null,
  )

  const countdown = computed(() => formatCountdown(task.value?.retry_after))

  // Aggregate retry-related derived state from the task snapshot.
  const retryState = computed(() => computeRetryState(
    task.value?.retry_of_task_id,
    task.value?.max_retries,
    task.value?.retry_count,
  ))

  const autoRetryConfigured = computed(() => retryState.value.autoRetryConfigured)
  const retryAttempt = computed(() => retryState.value.retryAttempt)
  const maxRetryAttempts = computed(() => retryState.value.maxRetryAttempts)
  const canAutoRetry = computed(() => retryState.value.canAutoRetry)
  const retriesExhausted = computed(() => retryState.value.retriesExhausted)
  const autoRetryDisabled = computed(() => retryState.value.autoRetryDisabled)

  async function cancelRetryChain(): Promise<void> {
    if (!task.value) return
    cancelling.value = true
    try {
      await taskStore.cancelRetryChain(task.value.id)
      await taskStore.fetchTask(task.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to cancel retry.')
    } finally {
      cancelling.value = false
    }
  }

  async function retryNow(): Promise<void> {
    if (!task.value) return
    errorBannerDismissed.value = true
    try {
      const newTask = await taskStore.retryTask(task.value.id)
      router.push({ name: 'task', params: { id: newTask.id } })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Retry failed.')
    }
  }

  function dismissBanner(): void {
    errorBannerDismissed.value = true
  }

  return {
    errorBannerDismissed,
    cancelling,
    showMaxStepsBanner,
    showRetryBanner,
    showNonRetryableErrorBanner,
    nonRetryableErrorMessage,
    showCountdown,
    countdown,
    autoRetryConfigured,
    retryAttempt,
    maxRetryAttempts,
    canAutoRetry,
    retriesExhausted,
    autoRetryDisabled,
    cancelRetryChain,
    retryNow,
    dismissBanner,
  }
}
