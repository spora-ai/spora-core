<script setup lang="ts">
import { ref, computed } from 'vue'
import Modal from '@/components/Modal.vue'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { LLMConfigResource, LLMDriverInfo } from '@/types/llmConfig'
import { ApiError } from '@/api/client'

const props = defineProps<{
  llmDrivers: LLMDriverInfo[]
  show: boolean
}>()

const emit = defineEmits<{
  'update:show': [value: boolean]
  created: [config: LLMConfigResource]
}>()

const llmStore = useLlmConfigsStore()

const formName = ref('')
const formDriverClass = ref('')
const formSettings = ref<Record<string, string>>({})
const saving = ref(false)
const error = ref<string | null>(null)

const activeDriver = computed<LLMDriverInfo | null>(
  () => props.llmDrivers.find((d) => d.driver_class === formDriverClass.value) ?? null,
)

function onDriverChange(): void {
  const driver = activeDriver.value
  if (!driver) return
  const defaults: Record<string, string> = {}
  for (const field of driver.settings_schema) {
    if (field.default !== undefined && field.default !== null) {
      defaults[field.key] = String(field.default)
    }
  }
  formSettings.value = defaults
  error.value = null
}

async function submit(settings: Record<string, string>): Promise<void> {
  if (!formDriverClass.value || !formName.value.trim()) return
  const driver = activeDriver.value
  if (!driver) return

  error.value = null
  saving.value = true
  try {
    const config = await llmStore.createConfig({
      name: formName.value.trim(),
      driver_class: driver.driver_class,
      settings: { ...settings },
    })
    emit('created', config)
    emit('update:show', false)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create configuration.'
  } finally {
    saving.value = false
  }
}

function close(): void {
  formName.value = ''
  formDriverClass.value = ''
  formSettings.value = {}
  error.value = null
  emit('update:show', false)
}
</script>

<template>
  <Modal
    :modelValue="show"
    title="New LLM Configuration"
    size="md"
    @update:modelValue="(v) => !v && close()"
    @close="close"
  >
    <div class="flex flex-col gap-4">
      <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>

      <!-- Name -->
      <div class="flex flex-col gap-1.5">
        <label for="llm-create-name" class="text-sm font-medium">Name</label>
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
      <div class="flex flex-col gap-1.5">
        <label for="llm-create-driver" class="text-sm font-medium">Driver</label>
        <select
          id="llm-create-driver"
          v-model="formDriverClass"
          @change="onDriverChange"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
        >
          <option value="">— Select a driver —</option>
          <option v-for="driver in llmDrivers" :key="driver.driver_class" :value="driver.driver_class">
            {{ driver.display_name }} ({{ driver.name }})
          </option>
        </select>
      </div>

      <!-- Settings -->
      <div v-if="activeDriver">
        <h3 class="text-sm font-semibold mb-3">Settings</h3>
        <ToolSettingsForm
          :tool="{ tool_class: activeDriver.driver_class, tool_name: activeDriver.name, display_name: activeDriver.display_name, category: '', settings_schema: activeDriver.settings_schema, operations: [] }"
          :initialSettings="formSettings"
          :saving="saving"
          :error="null"
          @save="submit"
        />
      </div>
      <div v-else-if="formDriverClass" class="text-sm text-muted-foreground py-4">
        Unknown driver. Please select a valid driver.
      </div>
      <div v-else class="text-sm text-muted-foreground py-4">
        Select a driver above to see available settings fields.
      </div>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="close"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          @click="submit(formSettings)"
          :disabled="saving || !formName.trim() || !formDriverClass"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ saving ? 'Creating…' : 'Create' }}
        </button>
      </div>
    </template>
  </Modal>
</template>
