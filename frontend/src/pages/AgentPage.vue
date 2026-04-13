<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useTaskStore } from '@/stores/tasks'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import TaskStatusBadge from '@/components/TaskStatusBadge.vue'
import { ApiError } from '@/api/client'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const taskStore = useTaskStore()

const agentId = computed(() => Number(route.params.id))

// ── Composer ─────────────────────────────────────────────────────────────────

const prompt = ref('')
const composerError = ref<string | null>(null)
const submitting = ref(false)
const confirmDeleteTaskId = ref<number | null>(null)

async function submitPrompt(): Promise<void> {
  const text = prompt.value.trim()
  if (!text) return
  composerError.value = null
  submitting.value = true
  try {
    const task = await taskStore.createTaskForAgent(agentId.value, text)
    prompt.value = ''
    router.push({ name: 'task', params: { id: task.id } })
  } catch (e) {
    composerError.value = e instanceof ApiError ? e.message : 'Failed to start task.'
  } finally {
    submitting.value = false
  }
}

function onComposerKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
    e.preventDefault()
    submitPrompt()
  }
}

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

// ── LLM Config detection ────────────────────────────────────────────────────

const llmConfig = ref<Record<string, string>>({})
const llmCheckDone = ref(false)

const llmUnconfigured = computed(() => {
  return Object.keys(llmConfig.value).length === 0
})

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

// ── Lifecycle ────────────────────────────────────────────────────────────────

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

  startPolling()
})

onMounted(async () => {
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

  startPolling()
})

onUnmounted(() => {
  stopPolling()
})
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">

    <GlobalNavbar />

    <div class="flex flex-1 overflow-hidden">

      <!-- ── Left Sidebar (desktop: lg+) ───────────────────────────────── -->
      <aside class="hidden lg:flex w-64 flex-col border-r border-border bg-muted/20 shrink-0 overflow-y-auto">
        <!-- Sidebar header -->
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
          <span class="text-sm font-medium text-muted-foreground">Agents</span>
          <button
            @click="router.push({ name: 'dashboard' })"
            class="flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
            title="Back to messages"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
          </button>
        </div>

        <!-- Agent list -->
        <ul class="flex-1 py-2">
          <li
            v-for="agent in agentStore.agents"
            :key="agent.id"
            @click="router.push({ name: 'agent', params: { id: agent.id } })"
            :class="[
              'flex items-center gap-3 px-4 py-2.5 cursor-pointer transition-colors',
              agent.id === agentId
                ? 'bg-primary/10 border-r-2 border-primary'
                : 'hover:bg-muted/60'
            ]"
          >
            <div class="shrink-0 h-8 w-8 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground">
              {{ agent.name.charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium truncate">{{ agent.name }}</p>
            </div>
          </li>
        </ul>
      </aside>

      <!-- ── Mobile sidebar toggle ─────────────────────────────────────── -->
      <!-- Mobile: show sidebar toggle button in header, full page layout on mobile -->

      <!-- ── Main Content ──────────────────────────────────────────────── -->
      <div class="flex-1 flex flex-col min-w-0">

        <!-- Agent header -->
        <div class="px-6 py-4 border-b border-border flex items-center gap-4 shrink-0">
          <!-- Mobile: hamburger + back button -->
          <button
            @click="router.push({ name: 'dashboard' })"
            class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors lg:hidden"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
          </button>

          <div class="flex-1 min-w-0">
            <h1 class="text-base font-semibold truncate">
              {{ agentStore.currentAgent?.name ?? 'Loading…' }}
            </h1>
            <p v-if="agentStore.currentAgent?.description" class="text-xs text-muted-foreground truncate mt-0.5">
              {{ agentStore.currentAgent.description }}
            </p>
          </div>

          <!-- Settings link -->
          <button
            @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
            class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
            title="Agent Settings"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </button>
        </div>

        <!-- Loading -->
        <div v-if="!agentStore.currentAgent && llmCheckDone" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
          Loading…
        </div>

        <template v-else-if="agentStore.currentAgent">

          <!-- ── Setup Banner ─────────────────────────────────────────── -->
          <div
            v-if="llmUnconfigured"
            class="mx-6 mt-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 flex items-start gap-3"
          >
            <svg class="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">LLM not configured</p>
              <p class="text-xs text-amber-700 dark:text-amber-300 mt-0.5">
                Add your API key before running tasks.
              </p>
            </div>
            <button
              @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
              class="shrink-0 inline-flex h-8 items-center justify-center rounded-lg bg-amber-600 hover:bg-amber-700 px-3 text-xs font-medium text-white transition-colors"
            >
              Configure
            </button>
          </div>

          <!-- ── Agent Summary Strip ─────────────────────────────────────── -->
          <div class="px-6 py-3 flex flex-wrap items-center gap-4 text-xs text-muted-foreground border-b border-border">

            <!-- LLM info -->
            <button
              @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
              class="flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
              title="Go to agent settings"
            >
              <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              <span v-if="agentStore.currentAgent.llm_driver_config_id">Custom LLM config</span>
              <span v-else>Global default LLM config</span>
            </button>

            <!-- Tools count -->
            <button
              @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
              class="flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
              title="Go to agent tools"
            >
              <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
              </svg>
              <span>{{ agentStore.currentAgent.tools.length }} tools enabled</span>
            </button>

            <!-- Max steps -->
            <button
              @click="router.push({ name: 'agent-settings', params: { id: agentId } })"
              class="flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
              title="Go to agent settings"
            >
              <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <span>Max {{ agentStore.currentAgent.max_steps }} steps</span>
            </button>
          </div>

          <!-- ── Task History ──────────────────────────────────────────── -->
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

          <!-- ── Composer ─────────────────────────────────────────────── -->
          <div class="shrink-0 border-t border-border bg-background">
            <div class="px-6 py-4">
              <div class="flex items-end gap-3">
                <div class="flex-1">
                  <textarea
                    v-model="prompt"
                    @keydown="onComposerKeydown"
                    rows="1"
                    placeholder="Message this agent…"
                    class="w-full resize-none rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    style="min-height: 48px; max-height: 160px"
                  />
                </div>
                <button
                  @click="submitPrompt"
                  :disabled="submitting || !prompt.trim()"
                  class="shrink-0 h-11 w-11 rounded-xl bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-7 7m7-7l7 7" />
                  </svg>
                </button>
              </div>
              <p v-if="composerError" role="alert" class="text-xs text-destructive mt-2">{{ composerError }}</p>
              <p v-else class="text-xs text-muted-foreground mt-2">⌘↵ to send</p>
            </div>
          </div>

        </template>
      </div>

    </div>

  </div>
</template>
