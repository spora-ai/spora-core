<script setup lang="ts">
/**
 * AgentPage — agent detail page with composer and task history.
 * Route: /agents/:id
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import ComposerInput from '@/components/ComposerInput.vue'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'

const confirmDeleteTaskId = ref<number | null>(null)

function confirmDelete(taskId: number, event: Event): void {
  event.stopPropagation()
  confirmDeleteTaskId.value = taskId
}

function cancelDelete(event: Event): void {
  event.stopPropagation()
  confirmDeleteTaskId.value = null
}

async function executeDelete(taskId: number, event: Event): Promise<void> {
  event.stopPropagation()
  try {
    await agentStore.deleteTask(taskId)
  } finally {
    confirmDeleteTaskId.value = null
  }
}

const llmConfig = ref<Record<string, string>>({})
const llmCheckDone = ref(false)

const llmUnconfigured = computed(() => {
  return Object.keys(llmConfig.value).length === 0
})

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const promptTemplatesStore = usePromptTemplatesStore()
const llmConfigsStore = useLlmConfigsStore()

const agentId = computed(() => Number(route.params.id))

// ── Relative time ───────────────────────────────────────────────────────────

function formatRelativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}

// ── Polling ─────────────────────────────────────────────────────────────────

let pollTimer: ReturnType<typeof setTimeout> | null = null

function startPolling(): void {
  stopPolling()
  const tick = async () => {
    const id = agentId.value
    if (!Number.isFinite(id)) return
    try {
      await agentStore.fetchAgentTasks(id)
    } finally {
      const hasActive = agentStore.currentAgentTasks.some(
        (t) => !['COMPLETED', 'FAILED'].includes(t.status),
      )
      pollTimer = setTimeout(tick, hasActive ? 3000 : 10000)
    }
  }
  pollTimer = setTimeout(tick, 3000)
}

function stopPolling(): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

// Refetch when navigating between agents (browser back/forward)
watch(agentId, async (newId) => {
  if (!Number.isFinite(newId)) {
    router.push({ name: 'dashboard' })
    return
  }
  stopPolling()
  agentStore.clearCurrentAgent()
  llmCheckDone.value = false
  await agentStore.fetchAgents()
  await agentStore.fetchAgent(newId)
  await agentStore.fetchAgentTasks(newId)

  try {
    llmConfig.value = (await agentStore.getLLMConfig(newId)) as Record<string, string>
  } catch {
    llmConfig.value = {}
  } finally {
    llmCheckDone.value = true
  }

  await promptTemplatesStore.fetchTemplates(newId)
  startPolling()
})

onMounted(async () => {
  await llmConfigsStore.ensure()
  agentStore.clearCurrentAgent()
  const id = agentId.value
  if (!Number.isFinite(id)) {
    router.push({ name: 'dashboard' })
    return
  }
  await agentStore.fetchAgents()
  await agentStore.fetchAgent(id)
  await agentStore.fetchAgentTasks(id)

  try {
    llmConfig.value = (await agentStore.getLLMConfig(id)) as Record<string, string>
  } catch {
    llmConfig.value = {}
  } finally {
    llmCheckDone.value = true
  }

  await promptTemplatesStore.fetchTemplates(id)
  startPolling()
})

onUnmounted(() => {
  stopPolling()
})
</script>

<template>
  <AgentLayout :agent-id="agentId" :llm-unconfigured="llmUnconfigured">

    <!-- Composer -->
    <ComposerInput :agent-id="agentId" />

    <!-- Task History -->
    <div class="flex-1 overflow-y-auto">

      <div v-if="agentStore.currentAgentTasks.length === 0" class="flex flex-col items-center justify-center py-16 px-6 text-center">
        <div class="h-12 w-12 rounded-full bg-muted flex items-center justify-center mb-4">
          <svg class="h-6 w-6 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
        </div>
        <p class="text-sm font-medium">No messages yet</p>
        <p class="text-xs text-muted-foreground mt-1">Start a conversation below</p>
      </div>

      <ul v-else class="divide-y divide-border">
        <li
          v-for="task in agentStore.currentAgentTasks"
          :key="task.id"
          class="flex items-center gap-4 px-6 py-4 hover:bg-muted/40 active:bg-muted transition-colors"
          :class="{ 'bg-muted/40': confirmDeleteTaskId === task.id }"
        >
          <!-- Inline delete confirmation -->
          <template v-if="confirmDeleteTaskId === task.id">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium truncate">Delete this conversation?</p>
            </div>
            <button
              @click="executeDelete(task.id, $event)"
              class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-red-600 hover:bg-red-700 px-3 text-xs font-medium text-white transition-colors"
            >
              Delete
            </button>
            <button
              @click="cancelDelete"
              class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg border border-border bg-background hover:bg-muted px-3 text-xs font-medium text-muted-foreground transition-colors"
            >
              Cancel
            </button>
          </template>

          <!-- Normal row -->
          <template v-else>
            <!-- Status indicator -->
            <span
              @click="router.push({ name: 'task', params: { id: task.id } })"
              class="shrink-0 h-2 w-2 rounded-full cursor-pointer"
              :class="{
                'bg-blue-500': task.status === 'RUNNING' || task.status === 'PENDING_APPROVAL',
                'bg-green-500': task.status === 'COMPLETED',
                'bg-red-500': task.status === 'FAILED',
                'bg-muted-foreground': task.status === 'PENDING',
              }"
            />
            <div
              @click="router.push({ name: 'task', params: { id: task.id } })"
              class="flex-1 min-w-0 cursor-pointer"
            >
              <p class="text-sm font-medium truncate">{{ task.user_prompt }}</p>
              <p class="text-xs text-muted-foreground mt-0.5">
                {{ formatRelativeTime(task.updated_at) }}
                <span v-if="task.step_count > 0"> · {{ task.step_count }} step{{ task.step_count !== 1 ? 's' : '' }}</span>
              </p>
            </div>
            <TaskStatusBadge :status="task.status" class="shrink-0" />
            <!-- Delete button -->
            <button
              @click="confirmDelete(task.id, $event)"
              class="shrink-0 h-8 w-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
              title="Delete conversation"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
            <!-- Chevron -->
            <svg
              @click="router.push({ name: 'task', params: { id: task.id } })"
              class="h-4 w-4 text-muted-foreground shrink-0 cursor-pointer"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </template>
        </li>
      </ul>
    </div>

  </AgentLayout>
</template>
