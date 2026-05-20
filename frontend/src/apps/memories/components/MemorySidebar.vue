<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Brain, Globe, Bot, ChevronDown, X, ChevronRight } from 'lucide-vue-next'
import { useMemoriesStore } from '../stores/memories'
import { useAgentStore } from '@/stores/agent'

const route = useRoute()
const router = useRouter()
const memoriesStore = useMemoriesStore()
const agentStore = useAgentStore()

defineProps<{
  mobileOpen?: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const selectedAgentId = ref<number | null>(null)
const showAgentDropdown = ref(false)

const isGlobalRoute = computed(() => route.name === 'global-memories')
const isAgentRoute = computed(() => route.name === 'agent-memories')

function selectAgent(agentId: number) {
  selectedAgentId.value = agentId
  showAgentDropdown.value = false
  memoriesStore.loadAgentMemories(agentId)
  router.push({ name: 'agent-memories', params: { id: String(agentId) } })
}

onMounted(async () => {
  await agentStore.fetchAgents()
  await memoriesStore.loadGlobalMemories()

  // Initialize selectedAgentId from URL or default to first agent
  const routeId = Number(route.params.id)
  if (!Number.isNaN(routeId)) {
    selectedAgentId.value = routeId
  } else if (agentStore.agents.length > 0 && selectedAgentId.value === null) {
    selectedAgentId.value = agentStore.agents[0].id
  }

  if (selectedAgentId.value !== null) {
    await memoriesStore.loadAgentMemories(selectedAgentId.value)
  }
})

const selectedAgentName = computed(() => {
  if (selectedAgentId.value === null) return 'Select agent'
  return agentStore.agents.find((a) => a.id === selectedAgentId.value)?.name ?? 'Unknown'
})

function navigateToMemory(memoryId: number) {
  router.push({ name: 'agent-memories', params: { id: String(selectedAgentId) }, query: { memory: String(memoryId) } })
}
</script>

<template>
  <aside class="w-64 flex-shrink-0 flex flex-col border-r border-border bg-card h-full">
    <!-- App header -->
    <div class="px-4 py-4 border-b border-border flex items-center justify-between">
      <div class="flex items-center gap-2">
        <Brain class="w-5 h-5 text-primary" />
        <span class="font-semibold text-sm">Memories</span>
      </div>
      <button
        v-if="mobileOpen"
        @click="emit('close')"
        class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      >
        <X class="w-4 h-4" />
      </button>
    </div>

    <!-- Scrollable content -->
    <div class="flex-1 overflow-y-auto">

      <!-- Global Memories -->
      <div class="px-3 py-3">
        <div class="flex items-center justify-between mb-2">
          <button
            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider hover:text-foreground transition-colors"
            :class="isGlobalRoute ? 'text-primary' : ''"
            @click="router.push({ name: 'global-memories' })"
          >
            <Globe class="w-3.5 h-3.5" />
            Global
          </button>
          <button
            class="flex items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
            @click="router.push({ name: 'global-memories' })"
          >
            View all
            <ChevronRight class="w-3 h-3" />
          </button>
        </div>

        <div v-if="memoriesStore.globalMemories.length === 0" class="text-xs text-muted-foreground py-1">
          No global memories.
        </div>
        <ul v-else class="space-y-0.5">
          <li
            v-for="memory in memoriesStore.globalMemories.slice(0, 5)"
            :key="memory.id"
            class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm hover:bg-muted/50 cursor-pointer transition-colors"
            :class="route.query.memory === String(memory.id) ? 'bg-muted text-foreground font-medium' : 'text-muted-foreground'"
            @click="router.push({ name: 'global-memories', query: { memory: String(memory.id) } })"
          >
            <span class="truncate flex-1 text-xs">{{ memory.name }}</span>
          </li>
        </ul>

        <button
          class="w-full flex items-center gap-2 h-7 px-2 mt-1 rounded-md border border-dashed border-border text-muted-foreground text-xs hover:bg-muted transition-colors"
          @click="router.push({ name: 'global-memories', query: { create: '1' } })"
        >
          <span class="w-3 text-center">+</span>
          <span class="truncate">New</span>
        </button>
      </div>

      <div class="border-t border-border mx-3" />

      <!-- Agent Memories -->
      <div class="px-3 py-3">
        <div class="flex items-center justify-between mb-2">
          <button
            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider hover:text-foreground transition-colors"
            :class="isAgentRoute ? 'text-primary' : ''"
            @click="selectedAgentId !== null && router.push({ name: 'agent-memories', params: { id: String(selectedAgentId) } })"
          >
            <Bot class="w-3.5 h-3.5" />
            Agent
          </button>
          <button
            v-if="selectedAgentId !== null"
            class="flex items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
            @click="router.push({ name: 'agent-memories', params: { id: String(selectedAgentId) } })"
          >
            View all
            <ChevronRight class="w-3 h-3" />
          </button>
        </div>

        <!-- Agent selector -->
        <div class="relative mb-2">
          <button
            @click="showAgentDropdown = !showAgentDropdown"
            class="w-full flex items-center justify-between h-8 px-2.5 rounded-lg border border-input bg-background text-xs hover:bg-muted transition-colors"
          >
            <span class="truncate flex items-center gap-1.5">
              <Bot class="w-3.5 h-3.5 shrink-0 text-muted-foreground" />
              <span class="truncate text-muted-foreground">{{ selectedAgentName }}</span>
            </span>
            <ChevronDown class="w-3.5 h-3.5 shrink-0 text-muted-foreground" />
          </button>
          <div
            v-if="showAgentDropdown"
            class="absolute top-full left-0 right-0 mt-1 rounded-lg border border-border bg-background shadow-md z-10"
          >
            <button
              v-for="agent in agentStore.agents"
              :key="agent.id"
              @click="selectAgent(agent.id)"
              class="w-full flex items-center gap-2 px-2.5 py-2 text-xs hover:bg-muted transition-colors first:rounded-t-lg last:rounded-b-lg"
              :class="agent.id === selectedAgentId ? 'bg-muted' : ''"
            >
              <Bot class="w-3.5 h-3.5 shrink-0 text-muted-foreground" />
              {{ agent.name }}
            </button>
          </div>
        </div>

        <div v-if="selectedAgentId === null" class="text-xs text-muted-foreground py-1">
          Select an agent.
        </div>
        <div v-else-if="memoriesStore.agentMemories.length === 0" class="text-xs text-muted-foreground py-1">
          No memories for this agent.
        </div>
        <ul v-else class="space-y-0.5">
          <li
            v-for="memory in memoriesStore.agentMemories.slice(0, 5)"
            :key="memory.id"
            class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm hover:bg-muted/50 cursor-pointer transition-colors"
            :class="route.query.memory === String(memory.id) ? 'bg-muted text-foreground font-medium' : 'text-muted-foreground'"
            @click="navigateToMemory(memory.id)"
          >
            <span class="truncate flex-1 text-xs">{{ memory.name }}</span>
          </li>
        </ul>

        <button
          class="w-full flex items-center gap-2 h-7 px-2 mt-1 rounded-md border border-dashed border-border text-muted-foreground text-xs hover:bg-muted transition-colors"
          @click="router.push({ name: 'agent-memories', params: { id: String(selectedAgentId ?? '') }, query: { create: '1' } })"
        >
          <span class="w-3 text-center">+</span>
          <span class="truncate">New</span>
        </button>
      </div>

    </div>
  </aside>
</template>
