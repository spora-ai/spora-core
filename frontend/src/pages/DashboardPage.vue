<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useTaskStore } from '@/stores/tasks'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import CreateAgentModal from '@/components/agent/CreateAgentModal.vue'

const router = useRouter()
const agentStore = useAgentStore()
const taskStore = useTaskStore()

const showNewAgentModal = ref(false)

// ── Helpers ─────────────────────────────────────────────────────────────────

function formatRelativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h`
  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d`
  return new Date(iso).toLocaleDateString()
}

function statusDot(status: string): string {
  if (status === 'RUNNING' || status === 'PENDING_APPROVAL') return 'bg-blue-500'
  if (status === 'COMPLETED') return 'bg-green-500'
  if (status === 'FAILED') return 'bg-red-500'
  return 'bg-muted-foreground'
}

const sortedAgents = computed(() => {
  const lastTasks = taskStore.lastTaskByAgent
  return [...agentStore.agents].sort((a, b) => {
    const ta = lastTasks.get(a.id)
    const tb = lastTasks.get(b.id)
    if (!ta && !tb) return 0
    if (!ta) return 1
    if (!tb) return -1
    return new Date(tb.updated_at).getTime() - new Date(ta.updated_at).getTime()
  })
})

// ── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(async () => {
  await agentStore.fetchAgents()
  await taskStore.fetchTasks()
})
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <main class="flex-1 flex flex-col">
      <!-- Header -->
      <div class="px-6 py-4 flex items-center justify-between border-b border-border shrink-0">
        <h1 class="text-lg font-semibold">Agents</h1>
        <button
          @click="showNewAgentModal = true"
          class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 transition-colors"
          title="New Agent"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
        </button>
      </div>

      <!-- Empty state -->
      <div
        v-if="agentStore.agents.length === 0"
        class="flex-1 flex flex-col items-center justify-center gap-4 px-6 text-center"
      >
        <div class="h-16 w-16 rounded-full bg-muted flex items-center justify-center">
          <svg class="h-8 w-8 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-medium">No conversations yet</p>
          <p class="text-xs text-muted-foreground mt-1">Create your first agent to start chatting</p>
        </div>
        <button
          @click="showNewAgentModal = true"
          class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          New Agent
        </button>
      </div>

      <!-- Contact list -->
      <ul v-else class="flex-1 overflow-y-auto divide-y divide-border">
        <li
          v-for="agent in sortedAgents"
          :key="agent.id"
          @click="router.push({ name: 'agent', params: { id: agent.id } })"
          class="flex items-center gap-4 px-6 py-4 cursor-pointer hover:bg-muted/50 active:bg-muted transition-colors"
        >
          <div class="shrink-0 h-12 w-12 rounded-full bg-muted flex items-center justify-center text-base font-semibold text-muted-foreground">
            {{ agent.name.charAt(0).toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-baseline justify-between gap-2">
              <p class="text-sm font-medium text-foreground truncate">{{ agent.name }}</p>
              <span
                v-if="taskStore.lastTaskByAgent.get(agent.id)"
                class="text-xs text-muted-foreground shrink-0"
              >
                {{ formatRelativeTime(taskStore.lastTaskByAgent.get(agent.id)!.updated_at) }}
              </span>
            </div>
            <div class="flex items-center gap-2 mt-0.5">
              <span
                v-if="taskStore.lastTaskByAgent.get(agent.id)"
                class="inline-block h-2 w-2 rounded-full shrink-0"
                :class="statusDot(taskStore.lastTaskByAgent.get(agent.id)!.status)"
              />
              <p class="text-xs text-muted-foreground truncate">
                {{ taskStore.lastTaskByAgent.get(agent.id)?.user_prompt ?? 'No messages yet' }}
              </p>
            </div>
          </div>
          <svg
            class="h-4 w-4 text-muted-foreground shrink-0"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </li>
      </ul>
    </main>

    <CreateAgentModal v-model="showNewAgentModal" />
  </div>
</template>