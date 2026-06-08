<script setup lang="ts">
/**
 * TaskChatMessageList — the scrollable chat history.
 *
 * Renders the user/assistant/tool bubbles, the final-response pill, the
 * failed banner, the running indicator, and a scroll anchor. The page owns
 * the scroll lifecycle and calls `scrollToBottom` after fetches + on new
 * history entries.
 */
import { computed, ref } from 'vue'
import type { TaskDetail } from '@/types/task'
import type { ChatMessage } from '@/composables/useTaskChat'
import { truncateText, isTruncated } from '@/composables/useTaskChat'
import { renderMarkdown } from '@/composables/useMarkdown'
import Icon from '@/components/ui/Icon.vue'
import TaskFailedBanner from '@/components/agent/TaskFailedBanner.vue'

interface Props {
  task: TaskDetail
  chatMessages: ChatMessage[]
  finalReasoning: string | null
  /** Per-sequence expanded flag; owned by the page so it survives remounts. */
  expandedTools?: Record<number, boolean>
}

const props = withDefaults(defineProps<Props>(), {
  expandedTools: () => ({}),
})

const emit = defineEmits<{
  toggleExpanded: [sequence: number]
}>()

const bottomEl = ref<HTMLDivElement | null>(null)

function scrollToBottom(): void {
  bottomEl.value?.scrollIntoView({ behavior: 'smooth' })
}

function truncate(content: string | null): string {
  return truncateText(content)
}

// Map tool_call_id (history row) to the tool call's structured result_data.
// History rows carry the LLM-side id, which matches ToolCall.provider_call_id;
// the DB id is also indexed for safety.
const toolResultDataByHistoryCallId = computed(() => {
  const map = new Map<string, Record<string, unknown>>()
  for (const tc of props.task.tool_calls ?? []) {
    if (tc.result_data) {
      map.set(tc.provider_call_id, tc.result_data)
      map.set(String(tc.id), tc.result_data)
    }
  }
  return map
})

function resultDataForEntry(entry: ChatMessage): Record<string, unknown> | null {
  if (entry.kind !== 'tool-result') return null
  const callId = entry.entry.tool_call_id
  if (!callId) return null
  return toolResultDataByHistoryCallId.value.get(callId) ?? null
}

function toolResultLinkTarget(entry: ChatMessage): number | string | null {
  const data = resultDataForEntry(entry)
  if (!data) return null
  const raw = data.new_task_id ?? data.task_id
  if (raw == null) return null
  return typeof raw === 'number' ? raw : String(raw)
}

function toolResultIsHandover(entry: ChatMessage): boolean {
  const data = resultDataForEntry(entry)
  return data?.handover === true
}

defineExpose({ scrollToBottom })
</script>

<template>
  <div class="flex-1 overflow-y-auto px-4 py-6 flex flex-col gap-3" data-testid="chat-message-list">

    <template v-for="msg in chatMessages" :key="msg.entry.sequence">

      <div v-if="msg.kind === 'user'" class="flex justify-end">
        <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-primary px-4 py-2.5 text-sm text-primary-foreground whitespace-pre-wrap break-words">
          {{ msg.entry.content }}
        </div>
      </div>

      <template v-if="msg.kind === 'assistant'">
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

        <div v-if="msg.entry.content" class="flex justify-start">
          <div class="flex gap-2.5 max-w-[85%]">
            <div class="shrink-0 h-7 w-7 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground mt-0.5">
              AI
            </div>
            <div class="rounded-2xl rounded-tl-sm border border-border bg-card px-4 py-2.5 text-sm">
              <div class="chat-bubble-content" v-html="renderMarkdown(msg.entry.content ?? '')" />
            </div>
          </div>
        </div>
      </template>

      <div v-if="msg.kind === 'tool-result'" class="flex justify-start">
        <details class="ml-9 max-w-[85%] text-xs rounded-lg border border-border bg-muted/40 overflow-hidden">
          <summary class="flex items-center gap-2 px-3 py-2 cursor-pointer select-none list-none hover:bg-muted/60 transition-colors">
            <Icon name="file" class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
            <span class="font-mono font-medium text-muted-foreground">{{ msg.entry.tool_name }}</span>
            <span class="text-muted-foreground/60">— result</span>
          </summary>
          <div class="px-3 py-2 border-t border-border chat-bubble-content text-muted-foreground break-all whitespace-pre-wrap">
            <template v-if="isTruncated(msg.entry.content)">
              <div class="flex flex-col gap-2">
                <div v-html="renderMarkdown(props.expandedTools[msg.entry.sequence] ? msg.entry.content ?? '' : truncate(msg.entry.content))" />
                <button
                  @click.stop.prevent="emit('toggleExpanded', msg.entry.sequence)"
                  class="mt-1 inline-flex items-center gap-0.5 px-2 py-0.5 rounded text-xs text-muted-foreground hover:text-foreground hover:bg-muted/60 transition-colors border border-transparent hover:border-border"
                >
                  {{ props.expandedTools[msg.entry.sequence] ? '▲ less' : '▼ more' }}
                </button>
              </div>
            </template>
            <div v-else v-html="renderMarkdown(truncate(msg.entry.content))" />
            <RouterLink
              v-if="toolResultLinkTarget(msg) !== null"
              :to="{ name: 'task', params: { id: String(toolResultLinkTarget(msg)) } }"
              class="mt-2 inline-flex items-center gap-1 text-xs text-primary hover:text-primary/80 transition-colors"
            >
              <template v-if="toolResultIsHandover(msg)">Handed off — </template>
              Open chat #{{ toolResultLinkTarget(msg) }} →
            </RouterLink>
          </div>
        </details>
      </div>

    </template>

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

    <div v-if="task.status === 'RUNNING'" class="flex justify-start">
      <div class="ml-9 flex gap-1 items-center px-3 py-2">
        <span
          v-for="i in 3" :key="i"
          class="inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground animate-bounce"
          :style="{ animationDelay: `${(i - 1) * 0.15}s` }"
        />
      </div>
    </div>

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

    <TaskFailedBanner v-if="task.status === 'FAILED'" :step-count="task.step_count" />

    <div ref="bottomEl"></div>
  </div>
</template>
