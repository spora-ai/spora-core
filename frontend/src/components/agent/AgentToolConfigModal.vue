<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import ToolSettingField from '@/components/settings/ToolSettingField.vue'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError, api } from '@/api/client'
import Icon from '@/components/ui/Icon.vue'
import { useRouter } from 'vue-router'

const props = defineProps<{
  toolName: string | null
  tool: ToolSchema | null
  agentId: number
}>()

const emit = defineEmits<{
  close: []
  saved: [toolName: string]
}>()

const router = useRouter()
const toolSettings = useToolSettings(props.agentId)

// State
const globalSettings = ref<Record<string, string>>({})
const rawOverride = ref<Record<string, string>>({})
const settingsWithSource = ref<SettingsWithSource>({})
const saving = ref(false)
const error = ref<string | null>(null)
const globalSettingsExist = ref(false)
const loadingSettings = ref(false)

// Override state
const overwriteAll = ref(false)
const overriddenFields = ref<Set<string>>(new Set())
const fieldErrors = ref<Record<string, string>>({})

// Local form state (effective values from global + local overrides)
const form = ref<Record<string, string>>({})

const hasSchema = computed(() => (props.tool?.settings_schema?.length ?? 0) > 0)
const hasMultipleFields = computed(() => (props.tool?.settings_schema?.length ?? 0) > 1)

async function loadSettings(toolName: string): Promise<void> {
  loadingSettings.value = true
  error.value = null
  globalSettingsExist.value = false
  overwriteAll.value = false
  overriddenFields.value = new Set()
  form.value = {}
  fieldErrors.value = {}

  // Fetch all in parallel: global settings, raw override, effective with source
  const [globalResult, rawResult, sourceResult] = await Promise.allSettled([
    toolSettings.getGlobalSettings(toolName),
    toolSettings.getRawOverride(toolName),
    toolSettings.getSettingsWithSource(toolName),
  ])

  // Global settings
  if (globalResult.status === 'fulfilled') {
    globalSettings.value = globalResult.value
    globalSettingsExist.value = Object.keys(globalResult.value).length > 0
  } else {
    globalSettings.value = {}
  }

  // Raw override (what's actually stored locally)
  if (rawResult.status === 'fulfilled') {
    rawOverride.value = rawResult.value
  } else {
    rawOverride.value = {}
  }

  // Effective settings with source
  if (sourceResult.status === 'fulfilled') {
    settingsWithSource.value = sourceResult.value
  } else {
    settingsWithSource.value = {}
  }

  // Initialize form with effective values and determine which fields are already overridden
  const effectiveValues: Record<string, string> = {}
  for (const [key, item] of Object.entries(settingsWithSource.value)) {
    effectiveValues[key] = String(item.value ?? '')
  }
  form.value = effectiveValues

  // Pre-check fields that have a local override
  for (const [key, item] of Object.entries(settingsWithSource.value)) {
    if (item.source === 'agent') {
      overriddenFields.value.add(key)
    }
  }

  loadingSettings.value = false
}

watch(
  () => props.toolName,
  (newTool) => {
    if (newTool) loadSettings(newTool)
  },
  { immediate: true },
)

function isOverridden(key: string): boolean {
  if (overwriteAll.value) return true
  return overriddenFields.value.has(key)
}

function toggleField(key: string): void {
  if (overwriteAll.value) return
  if (overriddenFields.value.has(key)) {
    overriddenFields.value.delete(key)
  } else {
    overriddenFields.value.add(key)
  }
  overriddenFields.value = new Set(overriddenFields.value)
}

function getSource(key: string): string {
  return settingsWithSource.value[key]?.source ?? 'default'
}

function getMaskedGlobalValue(key: string): string {
  const value = globalSettings.value[key]
  if (value === undefined || value === null) return '—'
  // Check if this field type is password
  const field = props.tool?.settings_schema.find((f) => f.key === key)
  if (field?.type === 'password') return '••••••••'
  return value
}

async function onSave(): Promise<void> {
  // Validate required fields.
  // Error only shown when ALL layers (schema default, global, user, agent) are empty for this field.
  fieldErrors.value = {}
  if (!props.tool) return
  for (const field of props.tool.settings_schema) {
    if (!field.required) continue
    const globalVal = String(globalSettings.value[field.key] ?? '').trim()
    const agentVal = String(form.value[field.key] ?? '').trim()
    const schemaDefault = field.default
    if (globalVal !== '' || agentVal !== '' || schemaDefault != null) continue
    // All layers empty — this field is missing a required value
    fieldErrors.value[field.key] = `${field.label} is required (no value in any layer)`
  }
  if (Object.keys(fieldErrors.value).length > 0) return

  saving.value = true
  error.value = null
  try {
    // Build settings to save.
    // When there is no global config to inherit from, every field belongs to the
    // agent override — save them all (same as overwriteAll).
    // When overwriteAll is explicitly checked, also save everything.
    // Otherwise only save the individually-toggled fields.
    const keysToSave = (!globalSettingsExist.value || overwriteAll.value)
      ? props.tool!.settings_schema.map((f) => f.key)
      : [...overriddenFields.value]

    const toSave: Record<string, string> = {}
    for (const key of keysToSave) {
      const value = form.value[key] ?? ''
      // '***' is the server's mask sentinel for an existing password value.
      // Skip it so we never overwrite a good stored value with the literal string '***'.
      if (value === '***') continue
      toSave[key] = value
    }

    await api.put(
      `/agents/${props.agentId}/tools/${encodeURIComponent(props.toolName!)}/override`,
      { settings: toSave },
    )

    emit('saved', props.toolName!)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}

async function removeLocalOverride(): Promise<void> {
  try {
    await api.delete(
      `/agents/${props.agentId}/tools/${encodeURIComponent(props.toolName!)}/override`,
    )
    emit('saved', props.toolName!)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to remove override.'
  }
}

function goToGlobalSettings(): void {
  emit('close')
  router.push({ name: 'settings-tools' })
}
</script>

<template>
  <Modal
    :modelValue="toolName !== null"
    :title="`Configure: ${tool?.display_name || toolName || ''}`"
    size="md"
    @update:modelValue="(v) => !v && emit('close')"
    @close="emit('close')"
  >
    <!-- Loading state -->
    <div v-if="loadingSettings" class="py-8 text-center text-sm text-muted-foreground">
      Loading settings…
    </div>

    <template v-else-if="tool && hasSchema">
      <!-- No global config warning -->
      <div
        v-if="!globalSettingsExist"
        class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 px-4 py-3 text-sm text-amber-700 dark:text-amber-300 mb-4"
      >
        <p class="font-medium mb-1">No global configuration found</p>
        <p class="text-xs opacity-80 mb-2">
          This tool has no global credentials set. Configure it locally below,
          or
          <button @click="goToGlobalSettings" class="underline hover:no-underline">
            set up global defaults first
          </button>
          for all agents.
        </p>
      </div>

      <!-- Global Configuration Preview (if exists) -->
      <div v-if="globalSettingsExist" class="mb-4">
        <div class="rounded-lg border border-border bg-muted/30">
          <div class="flex items-center justify-between px-4 py-2 border-b border-border">
            <span class="text-xs font-medium text-muted-foreground">Global Configuration (inherited)</span>
            <span class="text-xs text-green-600 dark:text-green-400 flex items-center gap-1">
              <Icon name="check" class="h-3 w-3" />
              Configured
            </span>
          </div>
          <div class="px-4 py-3 space-y-2">
            <div
              v-for="field in tool.settings_schema"
              :key="field.key"
              class="flex items-center justify-between text-xs"
            >
              <span class="text-muted-foreground">{{ field.label }}:</span>
              <span class="font-mono text-muted-foreground/80">
                {{ getMaskedGlobalValue(field.key) }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Overwrite All Toggle (only for multi-field tools) -->
      <div v-if="hasMultipleFields && globalSettingsExist" class="mb-4">
        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input
            type="checkbox"
            v-model="overwriteAll"
            class="rounded border-border"
          />
          <span>Override all settings locally</span>
        </label>
        <p class="text-xs text-muted-foreground mt-1 ml-5">
          Leave unchecked to inherit global values and only override specific fields.
        </p>
      </div>

      <!-- Per-Field Overrides -->
      <div class="space-y-4">
        <div v-for="field in tool.settings_schema" :key="field.key" class="flex flex-col gap-1.5">
          <!-- Field header: label on the left, override toggle on the right -->
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5">
              <span class="text-sm font-medium">{{ field.label }}</span>
              <span v-if="field.required" class="text-destructive text-xs">*</span>
              <span
                v-if="globalSettingsExist"
                class="text-xs px-1.5 py-0.5 rounded bg-muted text-muted-foreground"
              >
                {{ getSource(field.key) === 'agent' ? 'local' : 'global' }}
              </span>
            </div>
            <!-- Per-field override toggle (hidden when overwriteAll covers all fields) -->
            <label
              v-if="globalSettingsExist && !overwriteAll"
              class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer select-none"
            >
              <input
                type="checkbox"
                :checked="isOverridden(field.key)"
                @change="toggleField(field.key)"
                class="rounded border-border"
              />
              Override locally
            </label>
          </div>

          <ToolSettingField
            :modelValue="form[field.key] ?? ''"
            :field="field"
            :error="fieldErrors[field.key] ?? null"
            :disabled="globalSettingsExist && !overwriteAll && !isOverridden(field.key)"
            :hideLabel="true"
            @update:modelValue="form[field.key] = String($event ?? '')"
          />
        </div>
      </div>

      <!-- Error -->
      <p v-if="error" role="alert" class="text-xs text-destructive mt-4">{{ error }}</p>

      <!-- Remove local override (only shown when there's an existing override) -->
      <div v-if="Object.keys(rawOverride).length > 0" class="mt-4 pt-4 border-t border-border">
        <button
          type="button"
          @click="removeLocalOverride"
          class="text-xs text-muted-foreground hover:text-destructive transition-colors"
        >
          Remove local override and inherit global settings
        </button>
      </div>

      <!-- Actions -->
      <div class="flex justify-end gap-2 mt-6">
        <button
          type="button"
          @click="emit('close')"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
          type="button"
          @click="onSave"
          :disabled="saving"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : 'Save Locally' }}
        </button>
      </div>
    </template>

    <template v-else-if="tool && !hasSchema">
      <p class="text-sm text-muted-foreground py-4">This tool has no configurable settings.</p>
      <div class="flex justify-end">
        <button
          type="button"
          @click="emit('close')"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Close
        </button>
      </div>
    </template>
  </Modal>
</template>
