<script setup lang="ts">
/**
 * TaskChatBanners — all banner variants for the TaskChatPage.
 *
 * Renders the retry banner, the non-retryable error banner, the auto-retry
 * countdown (3 states), and the max-steps-reached banner. The page passes
 * the relevant state as props; this component is pure presentation so it
 * stays testable in isolation.
 */
import { computed } from 'vue'
import type { TaskDetail } from '@/types/task'
import { formatErrorCode } from '@/composables/useTaskChat'
import Icon from '@/components/ui/Icon.vue'

interface Props {
  task: TaskDetail | null
  showRetryBanner: boolean
  showNonRetryableErrorBanner: boolean
  nonRetryableErrorMessage: string | null
  showCountdown: boolean
  countdown: string
  canAutoRetry: boolean
  retriesExhausted: boolean
  autoRetryDisabled: boolean
  retryAttempt: number
  maxRetryAttempts: number
  cancelling: boolean
  showMaxStepsBanner: boolean
  followupPrompt: string
  submittingFollowup: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  retryNow: []
  cancelRetryChain: []
  dismissBanner: []
  updateFollowupPrompt: [value: string]
  submitFollowup: []
}>()

const errorCodeLabel = computed(() => formatErrorCode(props.task?.error_code))
</script>

<template>
  <div
    v-if="showRetryBanner"
    data-testid="retry-banner"
    class="mx-4 mt-4 max-w-2xl w-full mx-auto flex items-start gap-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm"
  >
    <Icon name="warning" class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
    <div class="flex-1 min-w-0">
      <p class="font-semibold text-red-900 dark:text-red-100">Task failed: {{ errorCodeLabel }}</p>
      <p v-if="task?.error_message" class="text-red-700 dark:text-red-300 mt-0.5">{{ task.error_message }}</p>
    </div>
    <button
      data-testid="retry-button"
      @click="emit('retryNow')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-medium shadow transition-colors px-3"
    >
      Retry Now
    </button>
    <button
      data-testid="dismiss-retry-banner-button"
      @click="emit('dismissBanner')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 text-xs px-2 hover:bg-red-100 dark:hover:bg-red-950/50 transition-colors"
    >
      Dismiss
    </button>
  </div>

  <div
    v-if="showNonRetryableErrorBanner"
    data-testid="non-retryable-error-banner"
    class="mx-4 mt-4 max-w-2xl mx-auto flex items-start gap-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm"
  >
    <Icon name="warning" class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
    <div class="flex-1 min-w-0">
      <p class="font-semibold text-red-900 dark:text-red-100">Task failed: {{ errorCodeLabel }}</p>
      <p v-if="nonRetryableErrorMessage" class="text-red-700 dark:text-red-300 mt-0.5">{{ nonRetryableErrorMessage }}</p>
    </div>
    <button
      data-testid="retry-button-non-retryable"
      @click="emit('retryNow')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-medium shadow transition-colors px-3"
    >
      Retry Now
    </button>
    <button
      data-testid="dismiss-non-retryable-banner-button"
      @click="emit('dismissBanner')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 text-xs px-2 hover:bg-red-100 dark:hover:bg-red-950/50 transition-colors"
    >
      Dismiss
    </button>
  </div>

  <div
    v-if="showCountdown && canAutoRetry"
    data-testid="retry-countdown"
    class="mx-4 mt-4 max-w-2xl mx-auto flex items-center gap-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm"
  >
    <Icon name="clock" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
    <div class="flex-1 min-w-0">
      <p class="font-semibold text-amber-900 dark:text-amber-100">
        Retrying in {{ countdown }} — Attempt {{ retryAttempt }} of {{ maxRetryAttempts }}
      </p>
      <p v-if="task?.error_code === 'ORPHANED'" class="text-amber-700 dark:text-amber-300 mt-0.5">
        Task was interrupted. A retry attempt is scheduled automatically.
      </p>
      <p v-else class="text-amber-700 dark:text-amber-300 mt-0.5">
        Task failed and will be retried automatically.
      </p>
    </div>
    <button
      data-testid="retry-button"
      @click="emit('retryNow')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
    >
      Retry Now
    </button>
    <button
      data-testid="cancel-retry-button"
      @click="emit('cancelRetryChain')"
      :disabled="cancelling"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-amber-300 dark:border-amber-800 text-amber-700 dark:text-amber-300 text-xs px-3 hover:bg-amber-100 dark:hover:bg-amber-950/50 transition-colors disabled:opacity-50"
    >
      {{ cancelling ? 'Cancelling…' : 'Cancel' }}
    </button>
  </div>

  <div
    v-else-if="showCountdown && retriesExhausted"
    data-testid="retry-countdown"
    class="mx-4 mt-4 max-w-2xl mx-auto flex items-center gap-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm"
  >
    <Icon name="clock" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
    <div class="flex-1 min-w-0">
      <p class="font-semibold text-amber-900 dark:text-amber-100">All retries exhausted.</p>
      <p class="text-amber-700 dark:text-amber-300 mt-0.5">
        No more automatic retries remaining.
      </p>
    </div>
    <button
      data-testid="retry-button"
      @click="emit('retryNow')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
    >
      Retry Now
    </button>
  </div>

  <div
    v-else-if="showCountdown && autoRetryDisabled"
    data-testid="retry-countdown"
    class="mx-4 mt-4 max-w-2xl mx-auto flex items-center gap-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm"
  >
    <Icon name="clock" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
    <div class="flex-1 min-w-0">
      <p class="font-semibold text-amber-900 dark:text-amber-100">Auto-retry not configured.</p>
      <p class="text-amber-700 dark:text-amber-300 mt-0.5">
        This task will not be retried automatically.
      </p>
    </div>
    <button
      data-testid="retry-button"
      @click="emit('retryNow')"
      class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
    >
      Retry Now
    </button>
  </div>

  <div
    v-if="showMaxStepsBanner"
    class="mx-4 mt-4 max-w-2xl mx-auto flex items-start gap-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-4 text-sm"
  >
    <Icon name="warning" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
    <div class="flex-1 min-w-0 flex flex-col gap-3">
      <div>
        <p class="font-semibold text-amber-900 dark:text-amber-100">Max steps reached.</p>
        <p class="text-amber-700 dark:text-amber-300 mt-0.5">
          This task used all {{ task?.step_count }} step{{ task?.step_count !== 1 ? 's' : '' }} (limit: {{ task?.max_steps }}).
        </p>
      </div>

      <div class="flex flex-col gap-1.5">
        <textarea
          :value="followupPrompt"
          @input="emit('updateFollowupPrompt', ($event.target as HTMLTextAreaElement).value)"
          rows="2"
          placeholder="Tell the agent what to do next…"
          class="w-full rounded-lg border border-amber-200 dark:border-amber-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-amber-900 dark:text-amber-100 placeholder:text-amber-400 dark:placeholder:text-amber-600 focus:outline-none focus:ring-1 focus:ring-amber-500 resize-none"
        />
        <div class="flex items-center gap-2">
          <button
            @click="emit('submitFollowup')"
            :disabled="submittingFollowup || !followupPrompt.trim()"
            class="inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-4 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ submittingFollowup ? 'Continuing…' : 'Reset steps & continue' }}
          </button>
          <span class="text-xs text-amber-700 dark:text-amber-300">— keeps the step limit, resets counter</span>
        </div>
      </div>
    </div>
  </div>
</template>
