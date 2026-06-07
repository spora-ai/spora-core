<script setup lang="ts">
/**
 * AgentLlmSection — current LLM display + "Change LLM" picker + the
 * "Create LLM" modal. Owns the LLM settings save flow and the modal
 * lifecycle; the page only provides the agent + agentId.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { ApiError, api } from '@/api/client'
import {
  buildInitialLlmSettings,
  buildLlmSettingsPayload,
  formatLlmConfigLabel,
  type LlmSettingsForm,
} from '@/composables/useAgentSettingsForm'
import AgentLlmConfigModal from '@/components/agent/AgentLlmConfigModal.vue'
import type { LLMDriverInfo } from '@/types/llmConfig'

interface LLMConfigResource {
  id: number
  name: string
  driver_display_name: string
  driver_class: string
  is_default: boolean
  is_global: boolean
}

interface Agent {
  id: number
  llm_driver_config_id?: number | null
}

const props = defineProps<{
  agent: Agent
  agentId: number
}>()

const configs = ref<LLMConfigResource[]>([])
const drivers = ref<LLMDriverInfo[]>([])

const form = ref<LlmSettingsForm>(buildInitialLlmSettings(props.agent))
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)
const showCreate = ref(false)

const currentConfig = computed(() =>
  configs.value.find((c) => c.id === form.value.llm_driver_config_id) ?? null,
)

function configLabel(config: LLMConfigResource): string {
  return formatLlmConfigLabel(config)
}

async function loadConfigs(): Promise<void> {
  const [configsResult, driversResult] = await Promise.all([
    api.get<{ configs: LLMConfigResource[] }>('/llm-configs'),
    api.get<{ drivers: LLMDriverInfo[] }>('/llm-drivers'),
  ])
  configs.value = configsResult.configs
  drivers.value = driversResult.drivers
}

function onLlmCreated(config: LLMConfigResource): void {
  configs.value.push(config)
  form.value.llm_driver_config_id = config.id
}

watch(
  () => props.agent,
  (next) => {
    form.value = buildInitialLlmSettings(next)
  },
)

onMounted(loadConfigs)

async function save(): Promise<void> {
  error.value = null
  saved.value = false
  saving.value = true
  try {
    await api.patch(`/agents/${props.agentId}`, buildLlmSettingsPayload(form.value))
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
    <h2 class="text-base font-semibold">LLM Configuration</h2>
    <div class="flex flex-col gap-1.5">
      <label for="llm-config" class="text-sm font-medium">LLM Config</label>
      <div class="flex items-center gap-2">
        <select
          id="llm-config"
          v-model="form.llm_driver_config_id"
          class="flex-1 rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
        >
          <option :value="null" disabled>Select an LLM config…</option>
          <option v-for="c in configs" :key="c.id" :value="c.id">{{ configLabel(c) }}</option>
        </select>
        <button
          data-testid="create-llm"
          @click="showCreate = true"
          type="button"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-3 text-sm font-medium hover:bg-muted transition-colors"
        >
          + New
        </button>
      </div>
      <p v-if="currentConfig" class="text-xs text-muted-foreground">Driver: {{ currentConfig.driver_display_name }}</p>
    </div>
    <div class="flex items-center justify-between">
      <p v-if="error" role="alert" data-testid="llm-error" class="text-xs text-destructive">{{ error }}</p>
      <span v-else-if="saved" data-testid="llm-saved" class="text-xs text-green-600 dark:text-green-400">Saved!</span>
      <span v-else />
      <button
        data-testid="save-llm"
        @click="save"
        :disabled="saving || form.llm_driver_config_id === null"
        class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
      >
        {{ saving ? 'Saving…' : 'Save LLM' }}
      </button>
    </div>

    <AgentLlmConfigModal
      :show="showCreate"
      :llmDrivers="drivers"
      @update:show="showCreate = $event"
      @created="onLlmCreated"
    />
  </section>
</template>
