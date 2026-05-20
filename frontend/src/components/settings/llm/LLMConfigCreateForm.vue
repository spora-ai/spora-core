<script setup lang="ts">
import { ref, computed } from 'vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import type { LLMConfigResource, LLMDriverInfo } from '@/types/llmConfig'

const props = defineProps<{
  /** When true, all created configs are forced global (admin context) */
  requireGlobal?: boolean
}>()

const emit = defineEmits<{
  created: [config: LLMConfigResource]
  cancel: []
}>()

const llmStore = useLlmConfigsStore()
const authStore = useAuthStore()

const formName = ref('')
const formDriverClass = ref('')
const formSettings = ref<Record<string, string>>({})
const formIsGlobal = ref(props.requireGlobal ?? false)
const formIsGlobalDefault = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const isAdmin = computed(() => authStore.user?.is_admin === true)

const activeDriver = computed<LLMDriverInfo | null>(
  () => llmStore.driverByName(formDriverClass.value) ?? null,
)

function onDriverChange(): void {
  const driver = llmStore.driverByName(formDriverClass.value)
  if (!driver) return
  const defaults: Record<string, string> = {}
  for (const field of driver.settings_schema) {
    if (field.default !== undefined && field.default !== null) {
      defaults[field.key] = String(field.default)
    }
  }
  formSettings.value = defaults
}

async function submit(settings: Record<string, string>): Promise<void> {
  if (!formDriverClass.value || !formName.value.trim()) return
  const driver = llmStore.driverByName(formDriverClass.value)
  if (!driver) return

  error.value = null
  saving.value = true
  try {
    const config = await llmStore.createConfig({
      name: formName.value.trim(),
      driver_class: driver.driver_class,
      settings: { ...settings },
      is_default: formIsGlobalDefault.value,
      is_global: formIsGlobal.value ? true : undefined,
    })
    emit('created', config)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create configuration.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mb-6">
    <h1 class="text-lg font-semibold">New LLM Configuration</h1>
    <p class="text-sm text-muted-foreground mt-0.5">Create a new LLM provider configuration.</p>
  </div>

  <div class="rounded-xl border border-border bg-card p-5">
    <!-- Name -->
    <div class="mb-5">
      <label for="llm-create-name" class="block text-sm font-medium mb-1.5">Name</label>
      <input
        id="llm-create-name"
        v-model="formName"
        type="text"
        placeholder="My OpenAI Config"
        autocomplete="off"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
      />
    </div>

    <!-- Driver -->
    <div class="mb-5">
      <label for="llm-create-driver" class="block text-sm font-medium mb-1.5">Driver</label>
      <select
        id="llm-create-driver"
        v-model="formDriverClass"
        @change="onDriverChange"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
      >
        <option value="">— Select a driver —</option>
        <option v-for="driver in llmStore.drivers" :key="driver.name" :value="driver.name">
          {{ driver.display_name }} ({{ driver.name }})
        </option>
      </select>
    </div>

    <!-- Admin: Make global (only for non-requireGlobal context) -->
    <div v-if="isAdmin && !props.requireGlobal" class="mb-5 space-y-3">
      <label class="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          v-model="formIsGlobal"
          class="rounded border-border text-primary focus:ring-primary"
        />
        <span class="text-sm font-medium">Make this a global configuration</span>
      </label>
      <p class="text-xs text-muted-foreground mt-1 ml-6">
        Global configurations are available to all users.
      </p>
      <label v-if="formIsGlobal" class="flex items-center gap-2 cursor-pointer ml-6">
        <input
          type="checkbox"
          v-model="formIsGlobalDefault"
          class="rounded border-border text-primary focus:ring-primary"
        />
        <span class="text-sm font-medium">Set as global default</span>
      </label>
    </div>

    <!-- Admin (requireGlobal): global-only default toggle -->
    <div v-if="props.requireGlobal" class="mb-5">
      <label class="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          v-model="formIsGlobalDefault"
          class="rounded border-border text-primary focus:ring-primary"
        />
        <span class="text-sm font-medium">Set as global default</span>
      </label>
      <p class="text-xs text-muted-foreground mt-1 ml-6">
        The default config is used by all agents without a custom LLM config.
      </p>
    </div>

    <!-- Settings form (appears after driver selected) -->
    <div v-if="formDriverClass && activeDriver">
      <h3 class="text-sm font-semibold mb-3">Settings</h3>
      <ToolSettingsForm
        :tool="{ tool_class: activeDriver.driver_class, tool_name: activeDriver.name, display_name: activeDriver.display_name, category: '', settings_schema: activeDriver.settings_schema, operations: [] }"
        :initialSettings="formSettings"
        :saving="saving"
        :error="error"
        @save="submit"
      />
    </div>
    <div v-else-if="formDriverClass && !activeDriver" class="text-sm text-muted-foreground py-4">
      Unknown driver. Please select a valid driver.
    </div>
    <div v-else class="text-sm text-muted-foreground py-4">
      Select a driver above to see available settings fields.
    </div>

    <div class="mt-4 flex justify-end">
      <button
        type="button"
        @click="$emit('cancel')"
        class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
      >
        Cancel
      </button>
    </div>
  </div>
</template>
