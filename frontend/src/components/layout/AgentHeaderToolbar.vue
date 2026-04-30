<script setup lang="ts">
/**
 * AgentHeaderToolbar — agent nav bar with tab navigation and optional LLM banner.
 * Used inside AgentLayout.
 */
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import Icon from '@/components/ui/Icon.vue'

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
        <Icon name="menu" />
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
          { name: 'agent', label: 'Chats', iconName: 'chat' },
          { name: 'scheduled-runs', label: 'Schedules', iconName: 'clock' },
          { name: 'agent-settings', label: 'Settings', iconName: 'settings' },
        ]"
        :key="tab.name"
        @click="navigate(tab.name)"
        class="relative flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors hover:text-foreground"
        :class="isActive(tab.name) ? 'text-primary' : 'text-muted-foreground'"
      >
        <Icon :name="tab.iconName" class="h-4 w-4 shrink-0" />
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
      <Icon name="warning" class="h-5 w-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
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
