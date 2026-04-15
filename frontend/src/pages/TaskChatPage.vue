<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTaskStore } from '@/stores/tasks'
import { useAgentStore } from '@/stores/agent'
import { ApiError } from '@/api/client'
import { renderMarkdown } from '@/composables/useMarkdown'
import { useToast } from '@/composables/useToast'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import type { HistoryEntry } from '@/types/task'

const route = useRoute()
const router = useRouter()
const taskStore = useTaskStore()
const agentStore = useAgentStore()
const toast = useToast()

const taskId = computed(() => Number(route.params.id))
const task = computed(() => taskStore.activeTask)
const pending = computed(() => taskStore.pendingToolCalls)

// Back navigation: go to the task's agent page if available, otherwise dashboard
const backDestination = computed(() => {
  if (task.value?.agent_id) {
    return { name: 'agent', params: { id: task.value.agent_id } }
  }
  return { name: 'dashboard' }
})

// Track whether we've successfully loaded the task at least once
// to distinguish "null during init" from "null after deletion"
let taskLoadSucceeded = false

// Approval state: one editable arguments object per pending tool call (keyed by tool call id)
const approvalArgs = ref<Record<number, string>>({})
const approveError = ref<string | null>(null)
const approvingAll = ref(false)
const rejectReason = ref('')
const rejecting = ref(false)
const showRejectInput = ref(false)

// Per-tool approval state (keyed by tool call id)
const perToolArgs = ref<Record<number, string>>({})
const perToolRejectReason = ref<Record<number, string>>({})
const perToolRejectInput = ref<Record<number, boolean>>({})
const perToolApproving = ref<Record<number, boolean>>({})
const perToolRejecting = ref<Record<number, boolean>>({})

// ── Follow-up ─────────────────────────────────────────────────────────────────

const followupPrompt = ref('')
const submittingFollowup = ref(false)
const followupError = ref<string | null>(null)

const showFollowupBar = computed(() => {
  if (!task.value) return false
  if (task.value.status !== 'COMPLETED' && task.value.status !== 'FAILED') return false
  const agent = agentStore.currentAgent
  if (!agent) return false
  return agent.allow_followup !== false
})

async function submitFollowup(): Promise<void> {
  const text = followupPrompt.value.trim()
  if (!text || !task.value) return
  followupError.value = null
  submittingFollowup.value = true
  try {
    const newTask = await taskStore.createTaskForAgent(task.value.agent_id, text, task.value.id)
    followupPrompt.value = ''
    router.push({ name: 'task', params: { id: newTask.id } })
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

// ── Chat rendering helpers ──────────────────────────────────────────────────

// Render a history entry's display text.
// assistant entries with null content are tool call requests — skip them;
// the tool calls array covers that.
type ChatMessage =
  | { kind: 'user'; entry: HistoryEntry }
  | { kind: 'assistant'; entry: HistoryEntry }
  | { kind: 'tool-result'; entry: HistoryEntry }

const chatMessages = computed((): ChatMessage[] => {
  if (!task.value) return []
  const result: ChatMessage[] = []
  for (const entry of task.value.history) {
    if (entry.role === 'user') {
      result.push({ kind: 'user', entry })
    } else if (entry.role === 'assistant' && entry.content) {
      result.push({ kind: 'assistant', entry })
    } else if (entry.role === 'tool') {
      result.push({ kind: 'tool-result', entry })
    }
    // assistant with null content = tool call request, rendered via pending panel
  }
  // Deduplicate: skip last assistant bubble if it matches final_response (trimmed)
  const last = result[result.length - 1]
  if (
    last?.kind === 'assistant' &&
    task.value.final_response !== null &&
    last.entry.content?.trim() === task.value.final_response.trim()
  ) {
    result.pop()
  }
  return result
})

function truncate(text: string | null, max = 300): string {
  if (!text) return '(empty)'
  return text.length <= max ? text : text.slice(0, max) + '…'
}

// ── Approval ───────────────────────────────────────────────────────────────

function initApprovalArgs(): void {
  const fresh: Record<number, string> = {}
  const perToolFresh: Record<number, string> = {}
  const calls = Array.isArray(pending.value) ? pending.value : []
  for (const tc of calls) {
    if (approvalArgs.value[tc.id] === undefined) {
      fresh[tc.id] = JSON.stringify(tc.proposed_arguments ?? {}, null, 2)
    } else {
      fresh[tc.id] = approvalArgs.value[tc.id]
    }
    if (perToolArgs.value[tc.id] === undefined) {
      perToolFresh[tc.id] = JSON.stringify(tc.proposed_arguments ?? {}, null, 2)
    } else {
      perToolFresh[tc.id] = perToolArgs.value[tc.id]
    }
  }
  approvalArgs.value = fresh
  perToolArgs.value = perToolFresh
}

watch(() => pending.value?.length ?? 0, initApprovalArgs, { immediate: true })

async function approveAll(): Promise<void> {
  approveError.value = null
  approvingAll.value = true
  try {
    const approvals = (pending.value ?? []).map((tc) => {
      let args: Record<string, unknown> = {}
      try {
        args = JSON.parse(perToolArgs.value[tc.id] ?? '{}') as Record<string, unknown>
      } catch {
        toast.error(`Invalid JSON for tool "${tc.tool_name}".`)
        throw new Error(`Invalid JSON for tool "${tc.tool_name}".`)
      }
      return { provider_call_id: String(tc.id), arguments: args }
    })
    await taskStore.approveTask(taskId.value, approvals)
    toast.success('All tools approved.')
    showRejectInput.value = false
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Approval failed.')
    approveError.value = e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Approval failed.'
  } finally {
    approvingAll.value = false
  }
}

async function reject(): Promise<void> {
  rejecting.value = true
  approveError.value = null
  try {
    await taskStore.rejectTask(taskId.value, rejectReason.value || 'No reason provided.')
    toast.success('Tool rejected.')
    rejectReason.value = ''
    showRejectInput.value = false
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Rejection failed.')
    approveError.value = e instanceof ApiError ? e.message : 'Rejection failed.'
  } finally {
    rejecting.value = false
  }
}

async function approveToolCall(tc: { id: number; tool_name: string }): Promise<void> {
  perToolApproving.value[tc.id] = true
  try {
    let args: Record<string, unknown> = {}
    try {
      args = JSON.parse(perToolArgs.value[tc.id] ?? '{}') as Record<string, unknown>
    } catch {
      toast.error(`Invalid JSON for tool "${tc.tool_name}".`)
      return
    }
    await taskStore.approveTask(taskId.value, [{ provider_call_id: String(tc.id), arguments: args }])
    toast.success(`Tool "${tc.tool_name}" approved.`)
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : `Failed to approve tool "${tc.tool_name}".`)
  } finally {
    perToolApproving.value[tc.id] = false
  }
}

async function rejectToolCall(tc: { id: number; tool_name: string }): Promise<void> {
  perToolRejecting.value[tc.id] = true
  try {
    const reason = perToolRejectReason.value[tc.id] || 'User rejected'
    await taskStore.rejectTask(taskId.value, reason)
    toast.success(`Tool "${tc.tool_name}" rejected.`)
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : `Failed to reject tool "${tc.tool_name}".`)
  } finally {
    perToolRejecting.value[tc.id] = false
    perToolRejectInput.value[tc.id] = false
    perToolRejectReason.value[tc.id] = ''
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

// Re-initialize when navigating between different tasks (component reuse without remount)
watch(taskId, async (newId, oldId) => {
  if (!Number.isFinite(newId) || newId === oldId) return
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

// Redirect to the agent page if task was deleted (e.g. via another tab or polling 404),
// but only after we successfully loaded it at least once (not on initial null)
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

  // Fetch agent so we can check allow_followup
  if (task.value?.agent_id) {
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
  <div class="min-h-screen bg-background flex flex-col">

    <!-- Header -->
    <header class="border-b border-border px-4 py-3 flex items-center gap-3 shrink-0">
      <button
        @click="router.push(backDestination)"
        class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Back
      </button>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium truncate">{{ task?.user_prompt ?? '…' }}</p>
      </div>
      <TaskStatusBadge v-if="task" :status="task.status" />
    </header>

    <!-- Loading state -->
    <div v-if="!task" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <template v-else>
      <!-- Chat area -->
      <div class="flex-1 overflow-y-auto px-4 py-6 flex flex-col gap-3 max-w-2xl w-full mx-auto">

        <template v-for="msg in chatMessages" :key="msg.entry.sequence">

          <!-- User message — right bubble -->
          <div v-if="msg.kind === 'user'" class="flex justify-end">
            <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-primary px-4 py-2.5 text-sm text-primary-foreground whitespace-pre-wrap break-words">
              {{ msg.entry.content }}
            </div>
          </div>

          <!-- Assistant text — left bubble -->
          <div v-else-if="msg.kind === 'assistant'" class="flex justify-start">
            <div class="flex gap-2.5 max-w-[85%]">
              <div class="shrink-0 h-7 w-7 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground mt-0.5">
                AI
              </div>
              <div class="rounded-2xl rounded-tl-sm border border-border bg-card px-4 py-2.5 text-sm chat-bubble-content">
                <div v-html="renderMarkdown(msg.entry.content ?? '')" />
              </div>
            </div>
          </div>

          <!-- Reasoning foldout -->
          <div v-if="msg.kind === 'assistant' && msg.entry.reasoning" class="flex justify-start">
            <div class="ml-9 mt-1 text-xs text-muted-foreground">
              <details class="rounded-lg border border-border">
                <summary class="px-3 py-1.5 cursor-pointer select-none">Reasoning</summary>
                <div class="px-3 py-2 border-t border-border chat-bubble-content" v-html="renderMarkdown(msg.entry.reasoning)" />
              </details>
            </div>
          </div>

          <!-- Tool result — compact card on the left -->
          <div v-else-if="msg.kind === 'tool-result'" class="flex justify-start">
            <details class="ml-9 max-w-[85%] text-xs rounded-lg border border-border bg-muted/40 overflow-hidden">
              <summary class="flex items-center gap-2 px-3 py-2 cursor-pointer select-none list-none hover:bg-muted/60 transition-colors">
                <svg class="h-3.5 w-3.5 text-muted-foreground shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                </svg>
                <span class="font-mono font-medium text-muted-foreground">{{ msg.entry.tool_name }}</span>
                <span class="text-muted-foreground/60">— result</span>
              </summary>
              <div class="px-3 py-2 border-t border-border chat-bubble-content text-muted-foreground break-all whitespace-pre-wrap">
                <div v-html="renderMarkdown(truncate(msg.entry.content ?? ''))" />
              </div>
            </details>
          </div>

        </template>

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
        <div v-if="task.status === 'FAILED'" class="flex justify-center">
          <div class="rounded-full bg-red-100 dark:bg-red-900/30 px-4 py-1.5 text-xs text-red-700 dark:text-red-300">
            Task failed after {{ task.step_count }} step{{ task.step_count !== 1 ? 's' : '' }}.
          </div>
        </div>

        <!-- Scroll anchor -->
        <div ref="bottomEl"></div>
      </div>

      <!-- Sticky Tool Approval Bar -->
      <div
        v-if="task.status === 'PENDING_APPROVAL' && pending.length > 0"
        class="border-t border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 shrink-0 sticky top-0 z-10"
      >
        <div class="max-w-2xl w-full mx-auto px-4 py-4 flex flex-col gap-4">

          <!-- Header: icon + title + Approve All / Reject All (only when >1 tool) -->
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 min-w-0">
              <svg class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <span class="text-sm font-semibold text-amber-800 dark:text-amber-200 truncate">
                {{ pending.length === 1 ? 'Tool approval required' : `${pending.length} tool approvals required` }}
              </span>
            </div>

            <!-- Approve All / Reject All — only when multiple tools pending -->
            <div v-if="pending.length > 1" class="flex gap-2 shrink-0">
              <button
                @click="approveAll"
                :disabled="approvingAll"
                class="inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors disabled:pointer-events-none disabled:opacity-50"
              >
                {{ approvingAll ? 'Approving…' : '✓ Approve All' }}
              </button>
              <button
                v-if="!showRejectInput"
                @click="showRejectInput = true"
                class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
              >
                ✗ Reject All
              </button>
              <template v-else>
                <button
                  @click="reject"
                  :disabled="rejecting"
                  class="inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-3 text-xs font-medium text-red-700 dark:text-red-300 hover:bg-red-100 transition-colors disabled:pointer-events-none disabled:opacity-50"
                >
                  {{ rejecting ? 'Rejecting…' : 'Confirm Reject All' }}
                </button>
                <button
                  @click="showRejectInput = false; rejectReason = ''"
                  class="inline-flex h-8 items-center justify-center rounded-lg border border-border px-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
                >
                  Cancel
                </button>
              </template>
            </div>
          </div>

          <!-- Reject All reason input -->
          <div v-if="showRejectInput && pending.length > 1" class="flex flex-col gap-1.5">
            <label class="text-xs font-medium text-muted-foreground">Reason for rejecting all tools</label>
            <input
              v-model="rejectReason"
              type="text"
              placeholder="Explain why you're rejecting all actions…"
              class="w-full rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          <!-- Error message -->
          <p v-if="approveError" role="alert" class="text-xs text-destructive">{{ approveError }}</p>

          <!-- One card per pending tool call -->
          <div
            v-for="tc in pending"
            :key="tc.id"
            class="rounded-xl border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-900 p-4 flex flex-col gap-3"
          >
            <!-- Tool name + description -->
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-semibold font-mono text-amber-900 dark:text-amber-100">{{ tc.tool_name }}</p>
                <p v-if="tc.human_description" class="text-xs text-muted-foreground mt-0.5">
                  {{ tc.human_description }}
                </p>
              </div>
            </div>

            <!-- Proposed arguments — collapsible JSON tree (read-only) -->
            <div class="rounded-lg border border-border bg-muted/20 overflow-hidden">
              <details class="group">
                <summary class="flex items-center gap-1.5 px-3 py-2 cursor-pointer select-none list-none text-xs text-muted-foreground hover:bg-muted/30 transition-colors">
                  <svg class="h-3 w-3 shrink-0 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                  </svg>
                  Proposed arguments
                </summary>
                <pre class="px-3 py-2 border-t border-border text-xs font-mono text-muted-foreground whitespace-pre-wrap break-all">{{ JSON.stringify(tc.proposed_arguments ?? {}, null, 2) }}</pre>
              </details>
            </div>

            <!-- Editable arguments textarea -->
            <div class="flex flex-col gap-1">
              <label class="text-xs font-medium text-muted-foreground">Edit arguments before approving</label>
              <textarea
                v-model="perToolArgs[tc.id]"
                rows="3"
                class="w-full resize-y rounded-lg border border-border bg-muted/30 px-3 py-2 font-mono text-xs focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>

            <!-- Per-tool reject reason input -->
            <div v-if="perToolRejectInput[tc.id]" class="flex flex-col gap-1">
              <label class="text-xs font-medium text-muted-foreground">Reason for rejecting "{{ tc.tool_name }}"</label>
              <input
                v-model="perToolRejectReason[tc.id]"
                type="text"
                :placeholder="`Explain why you're rejecting ${tc.tool_name}…`"
                class="w-full rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>

            <!-- Per-tool Approve / Reject buttons -->
            <div class="flex gap-2">
              <button
                @click="approveToolCall(tc)"
                :disabled="perToolApproving[tc.id]"
                class="inline-flex h-8 flex-1 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium shadow transition-colors disabled:pointer-events-none disabled:opacity-50"
              >
                {{ perToolApproving[tc.id] ? 'Approving…' : '✓ Approve' }}
              </button>
              <button
                v-if="!perToolRejectInput[tc.id]"
                @click="perToolRejectInput[tc.id] = true"
                class="inline-flex h-8 items-center justify-center rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
              >
                ✗ Reject
              </button>
              <template v-else>
                <button
                  @click="rejectToolCall(tc)"
                  :disabled="perToolRejecting[tc.id]"
                  class="inline-flex h-8 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-3 text-xs font-medium text-red-700 dark:text-red-300 hover:bg-red-100 transition-colors disabled:pointer-events-none disabled:opacity-50"
                >
                  {{ perToolRejecting[tc.id] ? 'Rejecting…' : 'Confirm Reject' }}
                </button>
                <button
                  @click="perToolRejectInput[tc.id] = false; perToolRejectReason[tc.id] = ''"
                  class="inline-flex h-8 items-center justify-center rounded-lg border border-border px-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
                >
                  Cancel
                </button>
              </template>
            </div>
          </div>
        </div>
      </div>

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

  </div>
</template>
