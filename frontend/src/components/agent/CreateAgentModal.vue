<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { ApiError } from '@/api/client'
import Icon from '@/components/ui/Icon.vue'

defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: [agent: { id: number }]
}>()

const router = useRouter()
const agentStore = useAgentStore()

const name = ref('')
const error = ref<string | null>(null)
const creating = ref(false)

async function submit(): Promise<void> {
  const trimmed = name.value.trim()
  if (!trimmed) return
  error.value = null
  creating.value = true
  try {
    const agent = await agentStore.createAgent({ name: trimmed })
    name.value = ''
    emit('update:modelValue', false)
    emit('created', agent)
    router.push({ name: 'agent', params: { id: agent.id } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create agent.'
  } finally {
    creating.value = false
  }
}

function close(): void {
  name.value = ''
  error.value = null
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
      @click.self="close"
    >
      <div class="bg-background rounded-2xl shadow-xl border border-border w-full max-w-sm p-6 flex flex-col gap-4">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold">New Agent</h2>
          <button
            @click="close"
            class="flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
          >
            <Icon name="x" class="h-4 w-4" />
          </button>
        </div>
        <form @submit.prevent="submit" class="flex flex-col gap-3">
          <div class="flex flex-col gap-1.5">
            <label for="agent-name" class="text-sm font-medium">Name</label>
            <input
              id="agent-name"
              v-model="name"
              type="text"
              placeholder="e.g. Research Assistant"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              autofocus
            />
          </div>
          <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              @click="close"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="creating || !name.trim()"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ creating ? 'Creating…' : 'Create Agent' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>