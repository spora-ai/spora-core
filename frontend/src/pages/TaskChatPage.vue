<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTaskStore } from '@/stores/tasks'
import { ApiError } from '@/api/client'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import type { HistoryEntry } from '@/types/task'

const route = useRoute()
const router = useRouter()
const taskStore = useTaskStore()

const taskId = computed(() => Number(route.params.id))
const task = computed(() => taskStore.activeTask)
const pending = computed(() => taskStore.pendingToolCalls)

// Approval state: one editable arguments object per pending tool call (keyed by tool call id)
const approvalArgs = ref<Record<number, string>>({})
const approveError = ref<string | null>(null)
const approvingAll = ref(false)
const rejectReason = ref('')
const rejecting = ref(false)
const showRejectInput = ref(false)

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
  return result
})

function truncate(text: string | null, max = 300): string {
  if (!text) return '(empty)'
  return text.length <= max ? text : text.slice(0, max) + '…'
}

// ── Approval ───────────────────────────────────────────────────────────────

function initApprovalArgs(): void {
  const fresh: Record<number, string> = {}
  for (const tc of pending.value) {
    // Preserve any edits the user has made
    if (approvalArgs.value[tc.id] === undefined) {
      fresh[tc.id] = JSON.stringify(tc.proposed_arguments ?? {}, null, 2)
    } else {
      fresh[tc.id] = approvalArgs.value[tc.id]
    }
  }
  approvalArgs.value = fresh
}

watch(() => pending.value.length, initApprovalArgs, { immediate: true })

async function approveAll(): Promise<void> {
  approveError.value = null
  approvingAll.value = true
  try {
    const approvals = pending.value.map((tc) => {
      let args: Record<string, unknown> = {}
      try {
        args = JSON.parse(approvalArgs.value[tc.id] ?? '{}') as Record<string, unknown>
      } catch {
        throw new Error(`Invalid JSON for tool "${tc.tool_name}".`)
      }
      return { provider_call_id: String(tc.id), arguments: args }
    })
    await taskStore.approveTask(taskId.value, approvals)
    showRejectInput.value = false
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
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
    rejectReason.value = ''
    showRejectInput.value = false
    taskStore.startDetailPolling(taskId.value)
    scrollToBottom()
  } catch (e) {
    approveError.value = e instanceof ApiError ? e.message : 'Rejection failed.'
  } finally {
    rejecting.value = false
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

watch(
  () => task.value?.history.length,
  () => scrollToBottom(),
)

onMounted(async () => {
  taskStore.clearActiveTask()
  await taskStore.fetchTaskDetail(taskId.value)
  scrollToBottom()
  if (task.value && !taskStore.isTerminal) {
    taskStore.startDetailPolling(taskId.value)
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
        @click="router.push({ name: 'dashboard' })"
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
              <div class="rounded-2xl rounded-tl-sm border border-border bg-card px-4 py-2.5 text-sm whitespace-pre-wrap break-words">
                {{ msg.entry.content }}
              </div>
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
              <div class="px-3 py-2 border-t border-border font-mono text-muted-foreground break-all whitespace-pre-wrap">
                {{ truncate(msg.entry.content) }}
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
            <div class="rounded-2xl rounded-tl-sm border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/30 px-4 py-2.5 text-sm whitespace-pre-wrap break-words text-green-900 dark:text-green-100">
              {{ task.final_response }}
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
        <div ref="bottomEl" />
      </div>

      <!-- Pending Approval panel -->
      <div
        v-if="task.status === 'PENDING_APPROVAL' && pending.length > 0"
        class="border-t border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 shrink-0"
      >
        <div class="max-w-2xl w-full mx-auto px-4 py-4 flex flex-col gap-4">
          <div class="flex items-center gap-2">
            <svg class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span class="text-sm font-semibold text-amber-800 dark:text-amber-200">
              {{ pending.length === 1 ? 'Agent wants to run a tool' : `Agent wants to run ${pending.length} tools` }}
            </span>
          </div>

          <!-- One card per pending tool call -->
          <div
            v-for="tc in pending"
            :key="tc.id"
            class="rounded-xl border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-900 p-4 flex flex-col gap-3"
          >
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="text-sm font-semibold font-mono">{{ tc.tool_name }}</p>
                <p v-if="tc.human_description" class="text-xs text-muted-foreground mt-0.5">
                  {{ tc.human_description }}
                </p>
              </div>
            </div>

            <div class="flex flex-col gap-1">
              <label class="text-xs font-medium text-muted-foreground">Arguments (editable)</label>
              <textarea
                v-model="approvalArgs[tc.id]"
                rows="4"
                class="w-full resize-y rounded-lg border border-border bg-muted/30 px-3 py-2 font-mono text-xs focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
          </div>

          <!-- Reject input -->
          <div v-if="showRejectInput" class="flex flex-col gap-2">
            <label class="text-xs font-medium text-muted-foreground">Reason for rejection</label>
            <input
              v-model="rejectReason"
              type="text"
              placeholder="Explain why you're rejecting this action…"
              class="w-full rounded-lg border border-border bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          <p v-if="approveError" role="alert" class="text-xs text-destructive">{{ approveError }}</p>

          <div class="flex gap-2">
            <button
              @click="approveAll"
              :disabled="approvingAll"
              class="inline-flex h-9 flex-1 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium shadow transition-colors disabled:pointer-events-none disabled:opacity-50"
            >
              {{ approvingAll ? 'Approving…' : 'Approve' }}
            </button>
            <button
              v-if="!showRejectInput"
              @click="showRejectInput = true"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-white dark:bg-zinc-900 px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
            >
              Reject
            </button>
            <template v-else>
              <button
                @click="reject"
                :disabled="rejecting"
                class="inline-flex h-9 items-center justify-center rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 text-sm font-medium text-red-700 dark:text-red-300 hover:bg-red-100 transition-colors disabled:pointer-events-none disabled:opacity-50"
              >
                {{ rejecting ? 'Rejecting…' : 'Confirm Reject' }}
              </button>
              <button
                @click="showRejectInput = false; rejectReason = ''"
                class="inline-flex h-9 items-center justify-center rounded-lg border border-border px-3 text-sm text-muted-foreground hover:text-foreground transition-colors"
              >
                Cancel
              </button>
            </template>
          </div>
        </div>
      </div>
    </template>

  </div>
</template>
