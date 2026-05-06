<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMemoriesStore } from '../stores/memories'
import MemoryListItem from '../components/MemoryListItem.vue'
import MemoryEditor from '../components/MemoryEditor.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { MemoryResource } from '../types/memory'
import type { CreateMemoryDto, UpdateMemoryDto } from '../types/memory'

const route = useRoute()
const router = useRouter()
const store = useMemoriesStore()

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
    const found = store.globalMemories.find((m) => m.id === id)
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
  await store.loadGlobalMemories()
  applyQueryParams()
})

watch(() => [route.query.create, route.query.memory], applyQueryParams)

async function handleSave(data: CreateMemoryDto | UpdateMemoryDto) {
  if (viewMode.value === 'edit' && selectedMemory.value) {
    const updated = await store.updateGlobalMemory(selectedMemory.value.id, data as UpdateMemoryDto)
    selectedMemory.value = updated
    router.replace({ name: 'global-memories', query: { memory: String(updated.id) } })
  } else {
    const created = await store.createGlobalMemory(data as CreateMemoryDto)
    selectedMemory.value = created
    viewMode.value = 'edit'
    router.replace({ name: 'global-memories', query: { memory: String(created.id) } })
  }
}

async function handleDelete() {
  if (!selectedMemory.value) return
  await store.deleteGlobalMemory(selectedMemory.value.id)
  handleCancel()
}

function handleCancel() {
  selectedMemory.value = null
  viewMode.value = 'list'
  router.replace({ name: 'global-memories' })
}
</script>

<template>
  <div class="flex-1 flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">Global Memories</h1>
        <p class="text-sm text-muted-foreground mt-1">Persistent memories shared across all your agents.</p>
      </div>
    </div>

    <!-- Error -->
    <AlertBanner v-if="store.error" type="error" :message="store.error" class="mb-4" />

    <!-- List view -->
    <template v-if="viewMode === 'list'">
      <div v-if="store.loadingGlobal" class="text-sm text-muted-foreground">Loading…</div>
      <div v-else-if="store.globalMemories.length === 0" class="rounded-xl border border-border bg-card p-8 text-center">
        <p class="text-sm text-muted-foreground mb-4">No global memories yet.</p>
        <button
          @click="router.push({ name: 'global-memories', query: { create: '1' } })"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
        >
          Create your first memory
        </button>
      </div>
      <div v-else class="rounded-xl border border-border bg-card divide-y divide-border">
        <MemoryListItem
          v-for="memory in store.globalMemories"
          :key="memory.id"
          :memory="memory"
          @select="router.push({ name: 'global-memories', query: { memory: String(memory.id) } })"
        />
      </div>
    </template>

    <!-- Create / Edit -->
    <template v-else>
      <MemoryEditor
        :memory="selectedMemory"
        :saving="store.saving"
        scope="global"
        @save="handleSave"
        @delete="handleDelete"
        @cancel="handleCancel"
      />
    </template>
  </div>
</template>
