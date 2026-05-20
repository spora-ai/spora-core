<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X } from 'lucide-vue-next'
import type { MemoryResource, CreateMemoryDto, UpdateMemoryDto } from '../types/memory'

const props = defineProps<{
  memory?: MemoryResource | null
  saving?: boolean
  scope?: 'global' | 'agent'
  agentName?: string
}>()

const emit = defineEmits<{
  save: [data: CreateMemoryDto | UpdateMemoryDto]
  delete: []
  cancel: []
}>()

const name = ref('')
const summary = ref('')
const content = ref('')

watch(
  () => props.memory,
  (m) => {
    name.value = m?.name ?? ''
    summary.value = m?.summary ?? ''
    content.value = m?.content ?? ''
  },
  { immediate: true },
)

const isEditing = computed(() => props.memory != null)

async function handleSubmit() {
  const data = {
    name: name.value.trim(),
    summary: summary.value.trim() || undefined,
    content: content.value || undefined,
  }
  emit('save', data)
}
</script>

<template>
  <div class="max-w-2xl">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-lg font-semibold">{{ isEditing ? 'Edit Memory' : 'New Memory' }}{{ agentName ? ` for ${agentName}` : '' }}</h2>
      <button
        @click="$emit('cancel')"
        class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      >
        <X class="w-4 h-4" />
      </button>
    </div>

    <form @submit.prevent="handleSubmit" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1.5">Name <span class="text-destructive">*</span></label>
        <input
          v-model="name"
          type="text"
          required
          placeholder="e.g. user_preferences, project_context"
          class="w-full h-9 rounded-lg border border-input bg-background px-3 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-ring"
        />
      </div>

      <div>
        <label class="block text-sm font-medium mb-1.5">Summary</label>
        <input
          v-model="summary"
          type="text"
          maxlength="500"
          placeholder="Brief one-line description (auto-derived if empty)"
          class="w-full h-9 rounded-lg border border-input bg-background px-3 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-ring"
        />
      </div>

      <div>
        <label class="block text-sm font-medium mb-1.5">Content <span class="text-muted-foreground text-xs">(Markdown)</span></label>
        <textarea
          v-model="content"
          rows="12"
          placeholder="Memory content in Markdown format..."
          class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-ring font-mono resize-y"
        />
      </div>

      <div class="flex items-center gap-3 pt-2">
        <button
          type="submit"
          :disabled="saving || !name.trim()"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ saving ? 'Saving…' : (isEditing ? 'Save Changes' : 'Create Memory') }}
        </button>

        <button
          v-if="isEditing"
          type="button"
          :disabled="saving"
          @click="$emit('delete')"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-destructive/30 px-4 text-sm font-medium text-destructive shadow-sm transition-colors hover:bg-destructive/10 disabled:opacity-50"
        >
          Delete
        </button>

        <button
          type="button"
          @click="$emit('cancel')"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border px-4 text-sm font-medium shadow-sm transition-colors hover:bg-muted disabled:opacity-50"
        >
          Cancel
        </button>
      </div>
    </form>
  </div>
</template>
