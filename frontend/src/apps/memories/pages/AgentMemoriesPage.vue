<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VueDraggable } from 'vue-draggable-plus'
import { useMemoriesStore } from '../stores/memories'
import { useAgentStore } from '@/stores/agent'
import MemoryListItem from '../components/MemoryListItem.vue'
import MemoryEditor from '../components/MemoryEditor.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { MemoryResource } from '../types/memory'
import type { CreateMemoryDto, UpdateMemoryDto } from '../types/memory'

const route = useRoute()
const router = useRouter()
const store = useMemoriesStore()
const agentStore = useAgentStore()

const agentId = computed(() => Number(route.params.id))
const agentName = computed(() => agentStore.agents.find((a) => a.id === agentId.value)?.name)
type ViewMode = 'list' | 'create' | 'edit'
const viewMode = ref<ViewMode>('list')
const selectedMemory = ref<MemoryResource | null>(null)

function applyQueryParams(): void {
  const createParam = route.query.create
  const memoryParam = route.query.memory

  if (createParam === '1') {
    viewMode.value = 'create'
    selectedMemory.value = null
  } else if (memoryParam) {
    const id = Number(memoryParam)
    const found = store.agentMemories.find((m) => m.id === id)
    if (found) {
      selectedMemory.value = found
      viewMode.value = 'edit'
    }
  } else {
    viewMode.value = 'list'
    selectedMemory.value = null
  }
}

onMounted(async () => {
  const validAgentId = Number.isNaN(agentId.value) ? null : agentId.value
  await Promise.all([
    agentStore.fetchAgents(),
    validAgentId !== null ? store.loadAgentMemories(validAgentId) : Promise.resolve(),
  ])
  applyQueryParams()
})

watch(() => [route.query.create, route.query.memory], applyQueryParams)

async function handleSave(data: CreateMemoryDto | UpdateMemoryDto) {
  if (viewMode.value === 'edit' && selectedMemory.value) {
    const updated = await store.updateAgentMemory(agentId.value, selectedMemory.value.id, data as UpdateMemoryDto)
    selectedMemory.value = updated
    router.replace({ query: { memory: String(updated.id) } })
  } else {
    const created = await store.createAgentMemory(agentId.value, data as CreateMemoryDto)
    selectedMemory.value = created
    viewMode.value = 'edit'
    router.replace({ query: { memory: String(created.id) } })
  }
}

async function handleDelete() {
  if (!selectedMemory.value) return
  await store.deleteAgentMemory(agentId.value, selectedMemory.value.id)
  handleCancel()
}

function handleCancel() {
  selectedMemory.value = null
  viewMode.value = 'list'
  router.replace({ query: {} })
}

async function handleDragEnd() {
  const orderedIds = store.agentMemories.map((m) => m.id)
  await store.reorderAgentMemories(agentId.value, orderedIds)
}
</script>

<template>
  <div class="flex-1 flex flex-col">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">Agent Memories</h1>
        <p class="text-sm text-muted-foreground mt-1">Persistent memories scoped to this agent.</p>
      </div>
    </div>

    <AlertBanner v-if="store.error" type="error" :message="store.error" class="mb-4" />

    <template v-if="viewMode === 'list'">
      <div v-if="store.loadingAgent" class="text-sm text-muted-foreground">Loading…</div>
      <div v-else-if="store.agentMemories.length === 0" class="rounded-xl border border-border bg-card p-8 text-center">
        <p class="text-sm text-muted-foreground mb-4">No agent memories yet.</p>
        <button
          @click="router.push({ query: { create: '1' } })"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
        >
          Create your first memory
        </button>
      </div>
      <div v-else class="rounded-xl border border-border bg-card divide-y divide-border">
        <VueDraggable
          v-model="store.agentMemories"
          item-key="id"
          @end="handleDragEnd"
        >
          <MemoryListItem
            v-for="memory in store.agentMemories"
            :key="memory.id"
            :memory="memory"
            show-handle
            @select="router.push({ query: { memory: String(memory.id) } })"
          />
        </VueDraggable>
      </div>
    </template>

    <template v-else>
      <MemoryEditor
        :memory="selectedMemory"
        :saving="store.saving"
        scope="agent"
        :agent-name="agentName"
        @save="handleSave"
        @delete="handleDelete"
        @cancel="handleCancel"
      />
    </template>
  </div>
</template>
