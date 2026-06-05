<script setup lang="ts">
/**
 * TaskChatPage — task detail / chat view.
 * Route: /tasks/:id
 */
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTaskStore } from '@/stores/tasks'
import { useAgentStore } from '@/stores/agent'
import { ApiError } from '@/api/client'
import { renderMarkdown } from '@/composables/useMarkdown'
import { useToast } from '@/composables/useToast'
import {
  formatCountdown,
  truncateText,
  isTruncated,
  computeRetryState,
  buildChatMessages,
  findFinalReasoning,
} from '@/composables/useTaskChat'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import Icon from '@/components/ui/Icon.vue'
import ToolApprovalBar from '@/components/agent/ToolApprovalBar.vue'
import TaskFailedBanner from '@/components/agent/TaskFailedBanner.vue'

const route = useRoute()
const router = useRouter()
const taskStore = useTaskStore()
const agentStore = useAgentStore()
const toast = useToast()

const taskId = computed(() => Number(route.params.id))
const task = computed(() => taskStore.activeTask)
const pending = computed(() => taskStore.pendingToolCalls)

// Back navigation

const backDestination = computed(() => {
  if (task.value?.agent_id) {
    return { name: 'agent', params: { id: task.value.agent_id } }
  }
  return { name: 'dashboard' }
})

// Track whether we've successfully loaded the task at least once
let taskLoadSucceeded = false

// Approval state — per-tool flags live in maps keyed by ToolCall.id so the
// ToolApprovalBar can mark only the in-flight card as "Approving…". The card
// owns its argument-edit state internally; we only track the HTTP-in-flight bit.

const approveError = ref<string | null>(null)
const approvingAll = ref(false)
const rejecting = ref(false)

const perToolApproving = ref<Record<number, boolean>>({})
const perToolRejecting = ref<Record<number, boolean>>({})

// Per-message expand/collapse state for long tool-result bubbles. Unrelated to
// the approval flow — these flags toggle truncation on the chat-stream side.
const expandedTools = ref<Record<number, boolean>>({})

// Error banner

const errorBannerDismissed = ref(false)

// Max steps reached banner
const showMaxStepsBanner = computed(() => {
  if (!task.value) return false
  if (task.value.status !== 'FAILED') return false
  if (task.value.failure_reason !== 'Max steps reached.') return false
  const agent = agentStore.currentAgent
  if (!agent) return false
  return agent.allow_continuation !== false
})

const RETRYABLE_ERROR_CODES = ['RATE_LIMIT', 'SERVER_OVERLOADED', 'SERVER_ERROR', 'GATEWAY_ERROR', 'AUTH_ERROR', 'LLM_TIMEOUT', 'ORPHANED'] as const

const showRetryBanner = computed(() => {
  if (!task.value) return false
  if (task.value.status !== 'FAILED') return false
  if (errorBannerDismissed.value) return false
  if (task.value.error_code === null) return false
  if (!RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof RETRYABLE_ERROR_CODES[number])) return false
  // Show banner when no scheduled countdown is available yet (fallback until
  // the backend provides retry_after). Once retry_after is set, the countdown
  // section takes over.
  if (!task.value.retry_after) {
    return (task.value.max_retries ?? 0) === 0 || !autoRetryConfigured.value
  }
  // retry_after is set — countdown is responsible; hide banner during auto-retry.
  if (canAutoRetry.value || retriesExhausted.value) return false
  // Auto-retry is disabled: still show banner so user can manually retry
  if ((task.value.max_retries ?? 0) === 0) return true
  return false
})

const NON_RETRYABLE_ERROR_CODES = ['NO_LLM_CONFIGURATION', 'UNKNOWN'] as const

// Shows error banner for non-retryable errors (NO_LLM_CONFIGURATION, etc.)
const showNonRetryableErrorBanner = computed(() => {
  if (!task.value) return false
  if (task.value.status !== 'FAILED') return false
  if (errorBannerDismissed.value) return false
  if (task.value.error_code === null) return false
  // Only show for errors that are NOT retryable
  if (RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof RETRYABLE_ERROR_CODES[number])) return false
  // Also exclude errors not in our known lists (safety check)
  if (!NON_RETRYABLE_ERROR_CODES.includes(task.value.error_code as typeof NON_RETRYABLE_ERROR_CODES[number])) return false
  return true
})

// For UNKNOWN errors, show raw failure_reason since error_message is generic
const nonRetryableErrorMessage = computed(() => {
  if (!task.value) return null
  if (task.value.error_code === 'UNKNOWN') {
    return task.value.failure_reason || task.value.error_message
  }
  return task.value.error_message
})

// Countdown for auto-retry (retry_after set but not yet elapsed)
const showCountdown = computed(() =>
  task.value?.status === 'FAILED' && task.value.retry_after !== null
)

const countdown = computed(() => formatCountdown(task.value?.retry_after))

// Aggregate retry-related derived state. Recomputed from the task snapshot.
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

const cancelling = ref(false)

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

// Follow-up

const followupPrompt = ref('')
const submittingFollowup = ref(false)
const followupError = ref<string | null>(null)

const showFollowupBar = computed(() => {
  if (!task.value) return false
  if (task.value.status !== 'COMPLETED' && task.value.status !== 'FAILED') return false
  const agent = agentStore.currentAgent
  if (!agent) return false
  return agent.allow_continuation !== false
})

async function submitFollowup(): Promise<void> {
  const text = followupPrompt.value.trim()
  if (!text || !task.value) return
  followupError.value = null
  submittingFollowup.value = true
  try {
    // Reset: no additionalSteps, keeps max_steps unchanged, step_count resets to 0
    await taskStore.continueTask(task.value.id, text)
    await taskStore.fetchTaskDetail(task.value.id)
    // Restart polling since the task is now RUNNING again
    if (!taskStore.isTerminal) {
      taskStore.startDetailPolling(task.value.id)
    }
    followupPrompt.value = ''
  } catch (e) {
    followupError.value = e instanceof ApiError ? e.message : 'Failed to submit follow-up.'
  } finally {
    submittingFollowup.value = false
  }
}

// Scroll anchor

const bottomEl = ref<HTMLDivElement | null>(null)

function scrollToBottom(): void {
  nextTick(() => bottomEl.value?.scrollIntoView({ behavior: 'smooth' }))
}

// Chat rendering helpers

// Reasoning from the last assistant message (before deduplication) - shown even when content is hidden
const finalReasoning = computed((): string | null => {
  return findFinalReasoning(task.value?.history, task.value?.final_response)
})

const chatMessages = computed(() => buildChatMessages(task.value?.history, task.value?.final_response))

function truncate(text: string | null, max = 300): string {
  return truncateText(text, max)
}

function toggleExpanded(toolId: number): void {
  expandedTools.value[toolId] = !expandedTools.value[toolId]
}

function isToolExpanded(toolId: number): boolean {
  return expandedTools.value[toolId] ?? false
}

// Approval handlers
//
// The sticky approval bar (ToolApprovalBar) owns the per-tool argument editing
// and the bulk approve-all / reject-all controls. This page just translates its
// events into store calls and surfaces toasts. The per-tool "approving" / "rejecting"
// in-flight maps are kept here so the bar can visually mark the correct card while
// the request is in flight.

async function onApproveAll(payload: { approvals: Array<{ providerCallId: string; arguments: Record<string, unknown> }> }): Promise<void> {
  approveError.value = null
  approvingAll.value = true
  try {
    // The bar snapshots edited arguments per card and sends them here, so
    // bulk approval submits exactly what the user sees in each editor.
    const approvals = payload.approvals.map((a) => ({
      provider_call_id: a.providerCallId,
      arguments: a.arguments,
    }))
    await taskStore.approveTask(taskId.value, approvals)
    toast.success('All tools approved.')
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    const msg = e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Approval failed.'
    toast.error(msg)
    approveError.value = msg
  } finally {
    approvingAll.value = false
  }
}

async function onRejectAll(payload: { reason: string }): Promise<void> {
  rejecting.value = true
  approveError.value = null
  try {
    await taskStore.rejectTask(taskId.value, payload.reason)
    toast.success('All tools rejected.')
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    const msg = e instanceof ApiError ? e.message : 'Rejection failed.'
    toast.error(msg)
    approveError.value = msg
  } finally {
    rejecting.value = false
  }
}

async function onApproveOne(payload: { providerCallId: string; arguments: Record<string, unknown> }): Promise<void> {
  // Match the card's id by provider_call_id so the bar can show "Approving…" on
  // the correct row.
  const tc = (pending.value ?? []).find(t => t.provider_call_id === payload.providerCallId)
  const id = tc?.id
  if (id !== undefined) perToolApproving.value[id] = true
  try {
    await taskStore.approveTask(taskId.value, [{ provider_call_id: payload.providerCallId, arguments: payload.arguments }])
    toast.success(`Tool "${tc?.tool_name ?? ''}" approved.`)
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : `Failed to approve tool "${tc?.tool_name ?? ''}".`)
  } finally {
    if (id !== undefined) perToolApproving.value[id] = false
  }
}

async function onRejectOne(payload: { providerCallId: string; reason: string }): Promise<void> {
  const tc = (pending.value ?? []).find(t => t.provider_call_id === payload.providerCallId)
  const id = tc?.id
  if (id !== undefined) perToolRejecting.value[id] = true
  try {
    await taskStore.rejectTask(taskId.value, payload.reason)
    toast.success(`Tool "${tc?.tool_name ?? ''}" rejected.`)
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : `Failed to reject tool "${tc?.tool_name ?? ''}".`)
  } finally {
    if (id !== undefined) perToolRejecting.value[id] = false
  }
}

// Lifecycle

watch(taskId, async (newId, oldId) => {
  if (!Number.isFinite(newId) || newId === oldId) return
  errorBannerDismissed.value = false
  taskStore.stopDetailPolling()
  taskStore.clearActiveTask()
  taskLoadSucceeded = false
  const found = await taskStore.fetchTaskDetail(newId)
  if (!found) {
    router.push(backDestination.value)
    return
  }
  taskLoadSucceeded = true
  if (task.value?.agent_id) {
    await agentStore.fetchAgents()
    await agentStore.fetchAgent(task.value.agent_id)
  }
  scrollToBottom()
  if (task.value && !taskStore.isTerminal) {
    taskStore.startDetailPolling(newId)
  }
})

watch(
  () => task.value?.history?.length ?? 0,
  () => scrollToBottom(),
)

watch(task, (newTask) => {
  if (taskLoadSucceeded && newTask === null) {
    router.push(backDestination.value)
  }
})

onMounted(async () => {
  const id = taskId.value
  if (!Number.isFinite(id)) {
    router.push(backDestination.value)
    return
  }
  taskStore.clearActiveTask()
  const found = await taskStore.fetchTaskDetail(id)
  if (!found) {
    router.push(backDestination.value)
    return
  }
  taskLoadSucceeded = true

  if (task.value?.agent_id) {
    await agentStore.fetchAgents()
    await agentStore.fetchAgent(task.value.agent_id)
  }

  scrollToBottom()
  if (task.value && !taskStore.isTerminal) {
    taskStore.startDetailPolling(id)
  }
})

onUnmounted(() => {
  taskStore.stopDetailPolling()
})
</script>

<template>
  <AgentLayout :agent-id="task?.agent_id ?? 0">

    <!-- Loading -->
    <div v-if="!task" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <template v-else>
      <!-- Task header -->
      <header class="border-b border-border px-4 py-3 flex items-center gap-3 shrink-0">
        <button
          @click="router.push(backDestination)"
          class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <Icon name="chevron-left" class="h-4 w-4" />
          Back
        </button>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium truncate">{{ task.user_prompt ?? '…' }}</p>
        </div>
        <TaskStatusBadge v-if="task" :status="task.status" />
      </header>

      <!-- Retryable error banner -->
      <div
        v-if="showRetryBanner"
        data-testid="retry-banner"
        class="mx-4 mt-4 max-w-2xl mx-auto flex items-start gap-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm"
      >
        <Icon name="warning" class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-red-900 dark:text-red-100">Task failed: {{ task.error_code?.replace('_', ' ').toLowerCase() }}</p>
          <p v-if="task.error_message" class="text-red-700 dark:text-red-300 mt-0.5">{{ task.error_message }}</p>
        </div>
        <button
          data-testid="retry-button"
          @click="retryNow"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-medium shadow transition-colors px-3"
        >
          Retry Now
        </button>
        <button
          data-testid="dismiss-retry-banner-button"
          @click="errorBannerDismissed = true"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 text-xs px-2 hover:bg-red-100 dark:hover:bg-red-950/50 transition-colors"
        >
          Dismiss
        </button>
      </div>

      <!-- Non-retryable error banner (NO_LLM_CONFIGURATION, UNKNOWN, etc.) -->
      <div
        v-if="showNonRetryableErrorBanner"
        data-testid="non-retryable-error-banner"
        class="mx-4 mt-4 max-w-2xl mx-auto flex items-start gap-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm"
      >
        <Icon name="warning" class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-red-900 dark:text-red-100">Task failed: {{ task.error_code?.replace('_', ' ').toLowerCase() }}</p>
          <p v-if="nonRetryableErrorMessage" class="text-red-700 dark:text-red-300 mt-0.5">{{ nonRetryableErrorMessage }}</p>
        </div>
        <button
          data-testid="retry-button-non-retryable"
          @click="retryNow"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-medium shadow transition-colors px-3"
        >
          Retry Now
        </button>
        <button
          data-testid="dismiss-non-retryable-banner-button"
          @click="errorBannerDismissed = true"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 text-xs px-2 hover:bg-red-100 dark:hover:bg-red-950/50 transition-colors"
        >
          Dismiss
        </button>
      </div>

      <!-- Auto-retry countdown — three states -->
      <!-- 1. Auto-retry active: countdown + attempt counter + Retry Now + Cancel -->
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
          <p v-if="task.error_code === 'ORPHANED'" class="text-amber-700 dark:text-amber-300 mt-0.5">
            Task was interrupted. A retry attempt is scheduled automatically.
          </p>
          <p v-else class="text-amber-700 dark:text-amber-300 mt-0.5">
            Task failed and will be retried automatically.
          </p>
        </div>
        <button
          data-testid="retry-button"
          @click="retryNow"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
        >
          Retry Now
        </button>
        <button
          data-testid="cancel-retry-button"
          @click="cancelRetryChain"
          :disabled="cancelling"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-amber-300 dark:border-amber-800 text-amber-700 dark:text-amber-300 text-xs px-3 hover:bg-amber-100 dark:hover:bg-amber-950/50 transition-colors disabled:opacity-50"
        >
          {{ cancelling ? 'Cancelling…' : 'Cancel' }}
        </button>
      </div>

      <!-- 2. Retries exhausted: show Retry Now instead of Cancel -->
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
          @click="retryNow"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
        >
          Retry Now
        </button>
      </div>

      <!-- 3. Auto-retry disabled (max_retries=0): show Retry Now -->
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
          @click="retryNow"
          class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors px-3"
        >
          Retry Now
        </button>
      </div>

      <!-- Max steps reached: reset step counter and continue -->
      <div
        v-if="showMaxStepsBanner"
        class="mx-4 mt-4 max-w-2xl mx-auto flex items-start gap-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-4 text-sm"
      >
        <Icon name="warning" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
        <div class="flex-1 min-w-0 flex flex-col gap-3">
          <div>
            <p class="font-semibold text-amber-900 dark:text-amber-100">Max steps reached.</p>
            <p class="text-amber-700 dark:text-amber-300 mt-0.5">
              This task used all {{ task.step_count }} step{{ task.step_count !== 1 ? 's' : '' }} (limit: {{ task.max_steps }}).
            </p>
          </div>

          <!-- Reset steps and continue (primary) -->
          <div class="flex flex-col gap-1.5">
            <textarea
              v-model="followupPrompt"
              rows="2"
              placeholder="Tell the agent what to do next…"
              class="w-full rounded-lg border border-amber-200 dark:border-amber-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-amber-900 dark:text-amber-100 placeholder:text-amber-400 dark:placeholder:text-amber-600 focus:outline-none focus:ring-1 focus:ring-amber-500 resize-none"
            />
            <div class="flex items-center gap-2">
              <button
                @click="submitFollowup"
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

      <!-- Chat area -->
      <div class="flex-1 overflow-y-auto px-4 py-6 flex flex-col gap-3">

        <template v-for="msg in chatMessages" :key="msg.entry.sequence">

          <!-- User message -->
          <div v-if="msg.kind === 'user'" class="flex justify-end">
            <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-primary px-4 py-2.5 text-sm text-primary-foreground whitespace-pre-wrap break-words">
              {{ msg.entry.content }}
            </div>
          </div>

          <!-- Assistant message: reasoning (above) + text (below) -->
          <template v-if="msg.kind === 'assistant'">
            <!-- Reasoning foldout -->
            <div v-if="msg.entry.reasoning" class="flex justify-start -mb-1.5">
              <div class="ml-9 mt-1 text-xs text-muted-foreground w-full max-w-[85%]">
                <details class="group">
                  <summary class="inline-flex items-center gap-1.5 px-1.5 py-0.5 cursor-pointer select-none list-none text-[11px] font-medium text-muted-foreground/60 hover:text-muted-foreground transition-colors">
                    <Icon name="chevron-right" class="h-3 w-3 transition-transform group-open:rotate-90" />
                    Reasoning
                  </summary>
                  <div class="mt-1.5 px-3 py-2 rounded-lg border border-border bg-muted/10 chat-bubble-content !text-[11px]" v-html="renderMarkdown(msg.entry.reasoning)" />
                </details>
              </div>
            </div>
            <!-- Assistant text -->
            <div v-if="msg.entry.content" class="flex justify-start">
              <div class="flex gap-2.5 max-w-[85%]">
                <div class="shrink-0 h-7 w-7 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground mt-0.5">
                  AI
                </div>
                <div class="rounded-2xl rounded-tl-sm border border-border bg-card px-4 py-2.5 text-sm">
                  <!-- eslint-disable-next-line vue/no-v-html -->
                  <div class="chat-bubble-content" v-html="renderMarkdown(msg.entry.content ?? '')" />
                </div>
              </div>
            </div>
          </template>

          <!-- Tool result -->
          <div v-if="msg.kind === 'tool-result'" class="flex justify-start">
            <details class="ml-9 max-w-[85%] text-xs rounded-lg border border-border bg-muted/40 overflow-hidden">
              <summary class="flex items-center gap-2 px-3 py-2 cursor-pointer select-none list-none hover:bg-muted/60 transition-colors">
                <Icon name="file" class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                <span class="font-mono font-medium text-muted-foreground">{{ msg.entry.tool_name }}</span>
                <span class="text-muted-foreground/60">— result</span>
              </summary>
              <div class="px-3 py-2 border-t border-border chat-bubble-content text-muted-foreground break-all whitespace-pre-wrap">
                <div v-if="isTruncated(msg.entry.content ?? '')" class="flex flex-col gap-2">
                  <div v-html="renderMarkdown(isToolExpanded(msg.entry.sequence) ? msg.entry.content ?? '' : truncate(msg.entry.content ?? ''))" />
                  <button
                    @click.stop="toggleExpanded(msg.entry.sequence)"
                    class="mt-1 inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-xs text-muted-foreground hover:text-foreground hover:bg-muted/60 transition-colors border border-transparent hover:border-border"
                  >
                    {{ isToolExpanded(msg.entry.sequence) ? '▲ less' : '▼ more' }}
                  </button>
                </div>
                <div v-else v-html="renderMarkdown(truncate(msg.entry.content ?? ''))" />
              </div>
            </details>
          </div>

        </template>

        <!-- Final reasoning (from last message before deduplication) -->
        <div v-if="finalReasoning" class="flex justify-start -mb-1.5">
          <div class="ml-9 mt-1 text-xs text-muted-foreground w-full max-w-[85%]">
            <details class="group">
              <summary class="inline-flex items-center gap-1.5 px-1.5 py-0.5 cursor-pointer select-none list-none text-[11px] font-medium text-muted-foreground/60 hover:text-muted-foreground transition-colors">
                <Icon name="chevron-right" class="h-3 w-3 transition-transform group-open:rotate-90" />
                Reasoning
              </summary>
              <div class="mt-1.5 px-3 py-2 rounded-lg border border-border bg-muted/10 chat-bubble-content !text-[11px]" v-html="renderMarkdown(finalReasoning)" />
            </details>
          </div>
        </div>

        <!-- Running indicator -->
        <div v-if="task.status === 'RUNNING'" class="flex justify-start">
          <div class="ml-9 flex gap-1 items-center px-3 py-2">
            <span
              v-for="i in 3" :key="i"
              class="inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground animate-bounce"
              :style="{ animationDelay: `${(i - 1) * 0.15}s` }"
            />
          </div>
        </div>

        <!-- Final response pill -->
        <div v-if="task.status === 'COMPLETED' && task.final_response" class="flex justify-start">
          <div class="flex gap-2.5 max-w-[85%]">
            <div class="shrink-0 h-7 w-7 rounded-full bg-green-100 dark:bg-green-900/40 flex items-center justify-center text-xs font-semibold text-green-700 dark:text-green-300 mt-0.5">
              ✓
            </div>
            <div class="rounded-2xl rounded-tl-sm border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/30 px-4 py-2.5 text-sm chat-bubble-content text-green-900 dark:text-green-100">
                <div v-html="renderMarkdown(task.final_response ?? '')" />
            </div>
          </div>
        </div>

        <!-- Failed pill -->
        <TaskFailedBanner v-if="task.status === 'FAILED'" :step-count="task.step_count" />

        <!-- Scroll anchor -->
        <div ref="bottomEl"></div>
      </div>

      <ToolApprovalBar
        v-if="task.status === 'PENDING_APPROVAL' && pending.length > 0"
        :pending="pending"
        :approve-error="approveError"
        :approving-all="approvingAll"
        :rejecting="rejecting"
        :per-tool-approving="perToolApproving"
        :per-tool-rejecting="perToolRejecting"
        @approve-all="onApproveAll"
        @reject-all="onRejectAll"
        @approve-one="onApproveOne"
        @reject-one="onRejectOne"
      />

      <!-- Follow-up Input Bar -->
      <div
        v-if="showFollowupBar"
        class="border-t border-border bg-background shrink-0"
      >
        <div class="max-w-2xl w-full mx-auto px-4 py-4 flex flex-col gap-2">
          <p class="text-xs text-muted-foreground font-medium">Continue conversation</p>
          <div class="flex items-end gap-3">
            <div class="flex-1">
              <textarea
                v-model="followupPrompt"
                rows="1"
                placeholder="Ask a follow-up question…"
                class="w-full resize-none rounded-lg border border-border bg-background px-3 py-2.5 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                style="min-height: 42px; max-height: 120px"
                @keydown.enter.exact.prevent="submitFollowup"
              />
            </div>
            <button
              @click="submitFollowup"
              :disabled="submittingFollowup || !followupPrompt.trim()"
              class="shrink-0 h-10 rounded-lg bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center px-4 text-sm font-medium"
            >
              {{ submittingFollowup ? 'Sending…' : 'Send' }}
            </button>
          </div>
          <p v-if="followupError" role="alert" class="text-xs text-destructive">{{ followupError }}</p>
        </div>
      </div>
    </template>

  </AgentLayout>
</template>
