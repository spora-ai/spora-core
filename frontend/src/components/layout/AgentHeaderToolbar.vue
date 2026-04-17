<script setup lang="ts">
/**
 * AgentHeaderToolbar — agent nav bar with tab navigation and optional LLM banner.
 * Used inside AgentLayout.
 */
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'

const props = defineProps<{
  agentId: number
  llmUnconfigured: boolean
}>()

const emit = defineEmits<{
  (e: 'open-sidebar'): void
}>()

const router = useRouter()
const route = useRoute()
const agentStore = useAgentStore()

function isActive(name: string): boolean {
  return route.name === name
}

function navigate(name: string): void {
  router.push({ name, params: { id: props.agentId } })
}
</script>

<template>
  <div class="bg-background shrink-0 flex flex-col relative z-20">
    <!-- Top toolbar row: agent info + sidebar toggle -->
    <div class="px-6 py-4 flex items-center gap-4 shrink-0 border-b border-border text-foreground">
      <!-- Mobile sidebar toggle -->
      <button
        @click="emit('open-sidebar')"
        class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors lg:hidden"
        title="Show agent list"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>

      <div class="flex-1 min-w-0">
        <h1 class="text-xl font-bold truncate">
          {{ agentStore.currentAgent?.name ?? 'Loading…' }}
        </h1>
        <p v-if="agentStore.currentAgent?.description" class="text-sm text-muted-foreground truncate mt-0.5">
          {{ agentStore.currentAgent.description }}
        </p>
      </div>
    </div>

    <!-- Tab bar -->
    <nav class="flex px-6 shrink-0 border-b border-border">
      <button
        v-for="tab in [
          { name: 'agent', label: 'Chats', icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
          { name: 'scheduled-runs', label: 'Schedules', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
          { name: 'agent-settings', label: 'Settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
        ]"
        :key="tab.name"
        @click="navigate(tab.name)"
        class="relative flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors hover:text-foreground"
        :class="isActive(tab.name) ? 'text-primary' : 'text-muted-foreground'"
      >
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="tab.icon" />
        </svg>
        {{ tab.label }}
        <!-- Active indicator -->
        <span
          v-if="isActive(tab.name)"
          class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-full"
        />
      </button>
    </nav>

    <!-- Agent Setup Banner (if llmUnconfigured) -->
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

  </div>
</template>
