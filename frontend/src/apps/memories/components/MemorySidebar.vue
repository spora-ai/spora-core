<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Brain, Globe, Bot, ChevronDown, X } from 'lucide-vue-next'
import { api } from '@/api/client'
import MemoryListItem from './MemoryListItem.vue'

interface MemoryResource {
  id: number
  user_id: number | null
  agent_id: number | null
  name: string
  summary: string | null
  content: string | null
  order: number
  created_at: string
  updated_at: string
}

const router = useRouter()

const agents = ref<Array<{ id: number; name: string }>>([])
const globalMemories = ref<MemoryResource[]>([])
const agentMemories = ref<MemoryResource[]>([])
const loading = ref(false)

defineProps<{
  mobileOpen?: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const selectedAgentId = ref<number | null>(null)
const showAgentDropdown = ref(false)

async function loadAgents() {
  try {
    const result = await api.get<{ agents: Array<{ id: number; name: string }> }>('/agents')
    agents.value = result.agents
    if (agents.value.length > 0 && selectedAgentId.value === null) {
      selectedAgentId.value = agents.value[0].id
    }
  } catch {
    // non-fatal
  }
}

async function loadAllMemories() {
  loading.value = true
  try {
    const [globalResult, agentResult] = await Promise.all([
      api.get<{ memories: MemoryResource[] }>('/memories'),
      selectedAgentId.value !== null
        ? api.get<{ memories: MemoryResource[] }>(`/agents/${selectedAgentId.value}/memories`)
        : Promise.resolve({ memories: [] }),
    ])
    globalMemories.value = globalResult.memories
    agentMemories.value = agentResult.memories
  } catch {
    globalMemories.value = []
    agentMemories.value = []
  } finally {
    loading.value = false
  }
}

function selectAgent(agentId: number) {
  selectedAgentId.value = agentId
  showAgentDropdown.value = false
  loadAllMemories()
}

function agentName(agentId: number | null): string {
  if (agentId === null) return 'Unknown'
  return agents.value.find((a) => a.id === agentId)?.name ?? 'Unknown'
}

const selectedAgentName = computed(() => agentName(selectedAgentId.value))

onMounted(async () => {
  await loadAgents()
  await loadAllMemories()
})
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

    <!-- Memory list -->
    <div class="flex-1 overflow-y-auto px-3 py-3">
      <div v-if="loading" class="text-sm text-muted-foreground text-center py-4">Loading…</div>
      <template v-else>
        <!-- Global memories section -->
        <div class="mb-4">
          <div class="flex items-center gap-1.5 px-1 mb-2">
            <Globe class="w-3.5 h-3.5 text-muted-foreground" />
            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Global</span>
          </div>
          <div v-if="globalMemories.length === 0" class="text-xs text-muted-foreground px-1 py-2">
            No global memories.
          </div>
          <div v-else class="space-y-1">
            <MemoryListItem
              v-for="memory in globalMemories"
              :key="memory.id"
              :memory="memory"
              @select="router.push({ name: 'global-memories', query: { memory: String(memory.id) } })"
            />
          </div>
          <button
            @click="router.push({ name: 'global-memories', query: { create: '1' } })"
            class="w-full flex items-center gap-2 h-8 px-2 mt-2 rounded-lg border border-dashed border-border text-muted-foreground text-xs hover:bg-muted transition-colors"
          >
            <Globe class="w-3.5 h-3.5 shrink-0" />
            New Global Memory
          </button>
        </div>

        <!-- Agent selector -->
        <div class="mb-4">
          <div class="flex items-center gap-1.5 px-1 mb-2">
            <Bot class="w-3.5 h-3.5 text-muted-foreground" />
            <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Agent: {{ selectedAgentName }}</span>
          </div>
          <div class="relative">
            <button
              @click="showAgentDropdown = !showAgentDropdown"
              class="w-full flex items-center justify-between h-9 px-3 rounded-lg border border-input bg-background text-sm hover:bg-muted transition-colors"
            >
              <span class="truncate flex items-center gap-2">
                <Bot class="w-4 h-4 text-muted-foreground shrink-0" />
                <span class="truncate">{{ selectedAgentName }}</span>
              </span>
              <ChevronDown class="w-4 h-4 text-muted-foreground flex-shrink-0" />
            </button>
            <div
              v-if="showAgentDropdown"
              class="absolute top-full left-0 right-0 mt-1 rounded-lg border border-border bg-background shadow-md z-10"
            >
              <button
                v-for="agent in agents"
                :key="agent.id"
                @click="selectAgent(agent.id)"
                class="w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-muted transition-colors first:rounded-t-lg last:rounded-b-lg"
                :class="agent.id === selectedAgentId ? 'bg-muted' : ''"
              >
                <Bot class="w-4 h-4 text-muted-foreground" />
                {{ agent.name }}
              </button>
            </div>
          </div>
        </div>

        <!-- Agent memories section -->
        <div>
          <div v-if="agentMemories.length === 0" class="text-xs text-muted-foreground px-1 py-2">
            No memories for this agent.
          </div>
          <div v-else class="space-y-1">
            <MemoryListItem
              v-for="memory in agentMemories"
              :key="memory.id"
              :memory="memory"
              @select="router.push({ name: 'agent-memories', params: { id: selectedAgentId ?? undefined }, query: { memory: String(memory.id) } })"
            />
          </div>
          <button
            @click="router.push({ name: 'agent-memories', params: { id: selectedAgentId ?? undefined }, query: { create: '1' } })"
            class="w-full flex items-center gap-2 h-8 px-2 mt-2 rounded-lg border border-dashed border-border text-muted-foreground text-xs hover:bg-muted transition-colors"
          >
            <Bot class="w-3.5 h-3.5 shrink-0" />
            New Agent Memory
          </button>
        </div>
      </template>
    </div>
  </aside>
</template>
