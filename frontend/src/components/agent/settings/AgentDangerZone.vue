<script setup lang="ts">
/**
 * AgentDangerZone — delete-agent confirmation form.
 *
 * Requires the user to type the agent name exactly. On success, emits a
 * `deleted` event and the page navigates away.
 */
import { ref } from 'vue'
import { useAgentStore } from '@/stores/agent'

interface Agent {
  id: number
  name: string
}

const props = defineProps<{
  agent: Agent
  agentId: number
}>()

const emit = defineEmits<{
  deleted: []
}>()

const agentStore = useAgentStore()
const deleting = ref(false)
const confirmName = ref('')
const error = ref<string | null>(null)

async function deleteAgent(): Promise<void> {
  if (confirmName.value !== props.agent.name) return
  error.value = null
  deleting.value = true
  try {
    await agentStore.deleteAgent(props.agentId)
    emit('deleted')
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to delete agent.'
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-destructive/30 bg-card p-5 flex flex-col gap-4">
    <h2 class="text-base font-semibold text-destructive">Danger Zone</h2>
    <div class="flex flex-col gap-1.5">
      <label for="delete-confirm" class="text-sm font-medium">Confirm deletion</label>
      <p class="text-xs text-muted-foreground">
        Type the agent name <strong>{{ agent.name }}</strong> to confirm.
      </p>
      <input
        id="delete-confirm"
        v-model="confirmName"
        type="text"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
    <p v-if="error" role="alert" data-testid="delete-error" class="text-xs text-destructive">{{ error }}</p>
    <div class="flex justify-end">
      <button
        data-testid="delete-agent"
        @click="deleteAgent"
        :disabled="deleting || confirmName !== agent.name"
        class="inline-flex h-9 items-center justify-center rounded-lg bg-destructive px-4 text-sm font-medium text-destructive-foreground shadow transition-colors hover:bg-destructive/90 disabled:pointer-events-none disabled:opacity-50"
      >
        {{ deleting ? 'Deleting…' : 'Delete Agent' }}
      </button>
    </div>
  </section>
</template>
