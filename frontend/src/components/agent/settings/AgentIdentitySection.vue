<script setup lang="ts">
/**
 * AgentIdentitySection — name, description, system prompt, max steps,
 * continuation, and auto-retry settings for an agent.
 *
 * Owns its own form state and the save flow (PATCH /agents/{id}). The page
 * only provides the agent + agentId.
 */
import { ref, watch } from 'vue'
import { ApiError, api } from '@/api/client'
import {
  buildInitialIdentityForm,
  buildIdentityPayload,
  type IdentityForm,
} from '@/composables/useAgentSettingsForm'

interface Agent {
  id: number
  name: string
  description?: string | null
  system_prompt?: string | null
  max_steps?: number | null
  allow_continuation?: boolean | null
  retry_after_minutes?: number | null
  max_retries?: number | null
}

const props = defineProps<{
  agent: Agent
  agentId: number
}>()

const form = ref<IdentityForm>(buildInitialIdentityForm(props.agent))
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)

watch(
  () => props.agent,
  (next) => {
    form.value = buildInitialIdentityForm(next)
  },
)

async function save(): Promise<void> {
  error.value = null
  saved.value = false
  saving.value = true
  try {
    await api.patch(`/agents/${props.agentId}`, buildIdentityPayload(form.value))
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
    <h2 class="text-base font-semibold">Identity</h2>
    <div class="flex flex-col gap-1.5">
      <label for="agent-name" class="text-sm font-medium">Name</label>
      <input
        id="agent-name"
        v-model="form.name"
        type="text"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
    <div class="flex flex-col gap-1.5">
      <label for="agent-desc" class="text-sm font-medium">Description <span class="text-muted-foreground font-normal">(optional)</span></label>
      <input
        id="agent-desc"
        v-model="form.description"
        type="text"
        placeholder="What does this agent do?"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
    <div class="flex flex-col gap-1.5">
      <label for="system-prompt" class="text-sm font-medium">System Prompt <span class="text-muted-foreground font-normal">(optional)</span></label>
      <textarea
        id="system-prompt"
        v-model="form.system_prompt"
        rows="4"
        placeholder="Additional instructions for the agent…"
        class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
    <div class="flex flex-col gap-1.5">
      <label for="max-steps" class="text-sm font-medium">Max Steps</label>
      <input
        id="max-steps"
        v-model.number="form.max_steps"
        type="number"
        min="1"
        max="100"
        placeholder="10"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
      />
      <p class="text-xs text-muted-foreground">Maximum number of agent turns (1–100).</p>
    </div>
    <div class="flex items-start gap-3">
      <input
        id="allow-continuation"
        v-model="form.allow_continuation"
        type="checkbox"
        class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
      />
      <div class="flex flex-col gap-1">
        <label for="allow-continuation" class="text-sm font-medium">Allow continuation</label>
        <p class="text-xs text-muted-foreground">When enabled, users can continue a conversation after a task completes.</p>
      </div>
    </div>

    <div class="border-t border-border pt-4 mt-2 flex flex-col gap-4">
      <h3 class="text-sm font-semibold">Auto-Retry</h3>
      <div class="grid grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <label for="retry-after-minutes" class="text-sm font-medium">Retry after (minutes)</label>
          <input
            id="retry-after-minutes"
            v-model.number="form.retry_after_minutes"
            type="number"
            min="0"
            placeholder="0 = disabled"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <p class="text-xs text-muted-foreground">Wait time before auto-retry (0 = disabled).</p>
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="max-retries" class="text-sm font-medium">Max retries</label>
          <input
            id="max-retries"
            v-model.number="form.max_retries"
            type="number"
            min="0"
            placeholder="0 = no auto-retry"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <p class="text-xs text-muted-foreground">Maximum retry attempts (0 = no auto-retry).</p>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-between">
      <p v-if="error" role="alert" data-testid="identity-error" class="text-xs text-destructive">{{ error }}</p>
      <span v-else-if="saved" data-testid="identity-saved" class="text-xs text-green-600 dark:text-green-400">Saved!</span>
      <span v-else />
      <button
        data-testid="save-identity"
        @click="save"
        :disabled="saving || !form.name.trim()"
        class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
      >
        {{ saving ? 'Saving…' : 'Save Identity' }}
      </button>
    </div>
  </section>
</template>
