<script setup lang="ts">
/**
 * TaskChatPage — task detail / chat view. Route: /tasks/:id.
 *
 * The page is a layout shell that wires the task store to focused
 * composables (retry, approvals, followup) and renders the chat area via
 * sub-components (banners, message list, approval bar, followup input).
 */
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTaskStore } from '@/stores/tasks'
import { useAgentStore } from '@/stores/agent'
import { useTaskChatRetry } from '@/composables/useTaskChatRetry'
import { useTaskChatApprovals } from '@/composables/useTaskChatApprovals'
import { useTaskChatFollowup } from '@/composables/useTaskChatFollowup'
import { buildChatMessages, findFinalReasoning } from '@/composables/useTaskChat'
import type { TaskDetail } from '@/types/task'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import ToolApprovalBar from '@/components/agent/ToolApprovalBar.vue'
import TaskChatBanners from '@/components/agent/TaskChat/TaskChatBanners.vue'
import TaskChatMessageList from '@/components/agent/TaskChat/TaskChatMessageList.vue'
import TaskChatFollowup from '@/components/agent/TaskChat/TaskChatFollowup.vue'

const route = useRoute()
const router = useRouter()
const taskStore = useTaskStore()
const agentStore = useAgentStore()

const taskId = computed(() => Number(route.params.id))
const task = computed(() => taskStore.activeTask)
const currentTask = computed(() => task.value as TaskDetail | null)
const pending = computed(() => taskStore.pendingToolCalls)

const backDestination = computed(() => {
  if (task.value?.agent_id) {
    return { name: 'agent', params: { id: task.value.agent_id } }
  }
  return { name: 'dashboard' }
})

const messageListRef = ref<InstanceType<typeof TaskChatMessageList> | null>(null)

function scrollToBottom(): void {
  nextTick(() => messageListRef.value?.scrollToBottom())
}

const retry = useTaskChatRetry()
const followup = useTaskChatFollowup()
const approvals = useTaskChatApprovals(taskId, scrollToBottom)

const chatMessages = computed(() =>
  buildChatMessages(task.value?.history, task.value?.final_response),
)

const finalReasoning = computed(() =>
  findFinalReasoning(task.value?.history, task.value?.final_response),
)

const expandedTools = ref<Record<number, boolean>>({})
function toggleExpanded(sequence: number): void {
  expandedTools.value[sequence] = !expandedTools.value[sequence]
}

// Tracks whether we've successfully loaded the task at least once; used to
// avoid bouncing the user back to the dashboard during a transient 404.
let taskLoadSucceeded = false

watch(taskId, async (newId, oldId) => {
  if (!Number.isFinite(newId) || newId === oldId) return
  taskLoadSucceeded = false
  taskStore.stopDetailPolling()
  taskStore.clearActiveTask()
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
  if (Number.isFinite(taskId.value)) {
    taskLoadSucceeded = false
    taskStore.stopDetailPolling()
    taskStore.clearActiveTask()
    const found = await taskStore.fetchTaskDetail(taskId.value)
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
      taskStore.startDetailPolling(taskId.value)
    }
  }
})

onUnmounted(() => {
  taskStore.stopDetailPolling()
})
</script>

<template>
  <AgentLayout :agent-id="currentTask?.agent_id ?? 0">
    <div v-if="!currentTask" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <div v-else class="flex-1 flex flex-col">

      <div class="px-4 py-3 border-b border-border flex items-center gap-3 shrink-0">
        <button
          @click="router.push(backDestination)"
          class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-muted transition-colors"
          aria-label="Back"
        >
          ←
        </button>
        <div class="flex-1 min-w-0">
          <h1 class="text-sm font-semibold truncate">{{ currentTask.user_prompt }}</h1>
          <div class="flex items-center gap-2 mt-0.5">
            <TaskStatusBadge :status="currentTask.status" />
            <span class="text-xs text-muted-foreground">Step {{ currentTask.step_count }}</span>
          </div>
        </div>
      </div>

      <TaskChatBanners
        :task="currentTask"
        :show-retry-banner="retry.showRetryBanner.value"
        :show-non-retryable-error-banner="retry.showNonRetryableErrorBanner.value"
        :non-retryable-error-message="retry.nonRetryableErrorMessage.value ?? null"
        :show-countdown="retry.showCountdown.value"
        :countdown="retry.countdown.value"
        :can-auto-retry="retry.canAutoRetry.value"
        :retries-exhausted="retry.retriesExhausted.value"
        :auto-retry-disabled="retry.autoRetryDisabled.value"
        :retry-attempt="retry.retryAttempt.value"
        :max-retry-attempts="retry.maxRetryAttempts.value"
        :cancelling="retry.cancelling.value"
        :show-max-steps-banner="retry.showMaxStepsBanner.value"
        :followup-prompt="followup.followupPrompt.value"
        :submitting-followup="followup.submittingFollowup.value"
        @retry-now="retry.retryNow"
        @cancel-retry-chain="retry.cancelRetryChain"
        @dismiss-banner="retry.dismissBanner"
        @update-followup-prompt="(v: string) => (followup.followupPrompt.value = v)"
        @submit-followup="followup.submitFollowup"
      />

      <TaskChatMessageList
        ref="messageListRef"
        :task="currentTask"
        :chat-messages="chatMessages"
        :final-reasoning="finalReasoning"
        :expanded-tools="expandedTools"
        @toggle-expanded="toggleExpanded"
      />

      <ToolApprovalBar
        v-if="currentTask.status === 'PENDING_APPROVAL' && pending.length > 0"
        :pending="pending"
        :approve-error="approvals.approveError.value"
        :approving-all="approvals.approvingAll.value"
        :rejecting="approvals.rejecting.value"
        :per-tool-approving="approvals.perToolApproving.value"
        :per-tool-rejecting="approvals.perToolRejecting.value"
        @approve-all="approvals.onApproveAll"
        @reject-all="approvals.onRejectAll"
        @approve-one="approvals.onApproveOne"
        @reject-one="approvals.onRejectOne"
      />

      <TaskChatFollowup
        :show-followup-bar="followup.showFollowupBar.value"
        :followup-prompt="followup.followupPrompt.value"
        :submitting-followup="followup.submittingFollowup.value"
        :followup-error="followup.followupError.value"
        @update-followup-prompt="(v: string) => (followup.followupPrompt.value = v)"
        @submit-followup="followup.submitFollowup"
      />
    </div>
  </AgentLayout>
</template>
