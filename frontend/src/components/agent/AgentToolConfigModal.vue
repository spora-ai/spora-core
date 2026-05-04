<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import ToolSettingField from '@/components/settings/ToolSettingField.vue'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError, api } from '@/api/client'
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
const userSettings = ref<Record<string, string>>({})
const userSettingsExist = ref(false)
const loadingSettings = ref(false)

// Override state
const overwriteAll = ref(false)
const overriddenFields = ref<Set<string>>(new Set())
const fieldErrors = ref<Record<string, string>>({})

// Local form state (agent-specific override values)
const form = ref<Record<string, string>>({})

const hasSchema = computed(() => (props.tool?.settings_schema?.length ?? 0) > 0)
const hasMultipleFields = computed(() => (props.tool?.settings_schema?.length ?? 0) > 1)

const hasAnyEffectiveSettings = computed(() => {
  return Object.values(settingsWithSource.value).some((item) => item.source !== 'default')
})

const agentOverridesExist = computed(() => Object.keys(rawOverride.value).length > 0)

async function loadSettings(toolName: string): Promise<void> {
  loadingSettings.value = true
  error.value = null
  globalSettingsExist.value = false
  userSettingsExist.value = false
  overwriteAll.value = false
  overriddenFields.value = new Set()
  form.value = {}
  fieldErrors.value = {}

  // Fetch all in parallel: global settings, raw override, effective with source, user settings
  const [globalResult, rawResult, sourceResult, userResult] = await Promise.allSettled([
    toolSettings.getGlobalSettings(toolName),
    toolSettings.getRawOverride(toolName),
    toolSettings.getSettingsWithSource(toolName),
    toolSettings.getUserSettings(toolName),
  ])

  // Global settings
  if (globalResult.status === 'fulfilled') {
    globalSettings.value = globalResult.value
    globalSettingsExist.value = Object.keys(globalResult.value).length > 0
  } else {
    globalSettings.value = {}
  }

  // Raw override (what's actually stored locally for this agent)
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

  // User settings
  if (userResult.status === 'fulfilled') {
    userSettings.value = userResult.value
    userSettingsExist.value = Object.keys(userResult.value).length > 0
  } else {
    userSettings.value = {}
  }

  // Initialize form with agent-specific override values (only fields where source === 'agent')
  const overrideValues: Record<string, string> = {}
  for (const [key, item] of Object.entries(settingsWithSource.value)) {
    if (item.source === 'agent') {
      overrideValues[key] = String(item.value ?? '')
    }
  }
  form.value = overrideValues

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

function isPasswordField(key: string): boolean {
  return props.tool?.settings_schema.find((f) => f.key === key)?.type === 'password' || false
}

function getMaskedValue(key: string): string {
  const item = settingsWithSource.value[key]
  if (!item || item.value === null || item.value === undefined) return '—'
  if (isPasswordField(key)) return '••••••••'
  return String(item.value)
}

function getSourceBadgeClass(source: string): string {
  switch (source) {
    case 'agent': return 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'
    case 'user': return 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
    case 'global': return 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
    default: return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'
  }
}

function getSourceLabel(source: string): string {
  switch (source) {
    case 'agent': return 'Agent'
    case 'user': return 'User'
    case 'global': return 'Global'
    default: return 'Default'
  }
}

async function onSave(): Promise<void> {
  fieldErrors.value = {}
  if (!props.tool) return

  saving.value = true
  error.value = null
  try {
    const keysToSave = overwriteAll.value
      ? props.tool!.settings_schema.map((f) => f.key)
      : [...overriddenFields.value].filter((k) => form.value[k] !== undefined)

    const toSave: Record<string, string> = {}
    for (const key of keysToSave) {
      const value = form.value[key] ?? ''
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

async function removeAgentOverride(): Promise<void> {
  try {
    await api.delete(
      `/agents/${props.agentId}/tools/${encodeURIComponent(props.toolName!)}/override`,
    )
    emit('saved', props.toolName!)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to remove agent override.'
  }
}

async function deleteGlobalSettings(): Promise<void> {
  try {
    await api.delete(`/tools/${encodeURIComponent(props.toolName!)}/settings`)
    emit('saved', props.toolName!)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to delete global settings.'
  }
}

async function deleteUserSettings(): Promise<void> {
  try {
    await api.delete(`/tools/${encodeURIComponent(props.toolName!)}/user-settings`)
    emit('saved', props.toolName!)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to delete user settings.'
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
      <!-- ============================================ -->
      <!-- SECTION 1: Currently Active Settings (Read-only info) -->
      <!-- ============================================ -->
      <div class="mb-6">
        <h3 class="text-sm font-medium text-foreground mb-3">Currently Active Settings</h3>
        <div class="rounded-lg border border-border bg-muted/30">
          <div class="px-4 py-3 space-y-2">
            <div
              v-for="field in tool.settings_schema"
              :key="field.key"
              class="flex items-center justify-between text-sm"
            >
              <span class="text-muted-foreground">{{ field.label }}</span>
              <div class="flex items-center gap-2">
                <span class="font-mono text-muted-foreground/80">
                  {{ getMaskedValue(field.key) }}
                </span>
                <span
                  class="text-xs px-1.5 py-0.5 rounded"
                  :class="getSourceBadgeClass(getSource(field.key))"
                >
                  {{ getSourceLabel(getSource(field.key)) }}
                </span>
              </div>
            </div>
          </div>
          <div v-if="!hasAnyEffectiveSettings" class="px-4 py-3 text-xs text-muted-foreground">
            Using defaults (no settings configured)
          </div>
        </div>
      </div>

      <!-- ============================================ -->
      <!-- SECTION 2: Agent-Level Overrides -->
      <!-- ============================================ -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-medium text-foreground">Agent-Level Overrides</h3>
          <button
            v-if="agentOverridesExist"
            type="button"
            @click="removeAgentOverride"
            class="text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition-colors"
          >
            Remove all agent overrides
          </button>
        </div>

        <p class="text-xs text-muted-foreground mb-4">
          Override settings specifically for this agent. Leave empty to inherit from global/user settings.
        </p>

        <!-- Overwrite All Toggle (only for multi-field tools) -->
        <div v-if="hasMultipleFields" class="mb-4">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              v-model="overwriteAll"
              class="rounded border-border"
            />
            <span>Override all fields</span>
          </label>
        </div>

        <!-- Per-Field Override Form -->
        <div class="space-y-4">
          <div v-for="field in tool.settings_schema" :key="field.key" class="flex flex-col gap-1.5">
            <!-- Field header: label + override toggle -->
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-1.5">
                <span class="text-sm font-medium">{{ field.label }}</span>
                <span v-if="field.required" class="text-destructive text-xs">*</span>
                <span
                  v-if="getSource(field.key) !== 'default'"
                  class="text-xs px-1.5 py-0.5 rounded"
                  :class="getSourceBadgeClass(getSource(field.key))"
                >
                  {{ getSourceLabel(getSource(field.key)) }}
                </span>
              </div>
              <label
                v-if="!overwriteAll"
                class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer select-none"
              >
                <input
                  type="checkbox"
                  :checked="isOverridden(field.key)"
                  @change="toggleField(field.key)"
                  class="rounded border-border"
                />
                Override
              </label>
            </div>

            <ToolSettingField
              :modelValue="form[field.key] ?? ''"
              :field="field"
              :error="fieldErrors[field.key] ?? null"
              :disabled="!overwriteAll && !isOverridden(field.key)"
              :hideLabel="true"
              @update:modelValue="form[field.key] = String($event ?? '')"
            />
          </div>
        </div>
      </div>

      <!-- Error -->
      <p v-if="error" role="alert" class="text-xs text-destructive mt-4">{{ error }}</p>

      <!-- ============================================ -->
      <!-- SECTION 3: Danger Zone -->
      <!-- ============================================ -->
      <div class="mt-6 pt-4 border-t border-border">
        <p class="text-xs font-medium text-muted-foreground mb-3">Manage Other Settings</p>
        <div class="flex flex-wrap gap-4">
          <!-- Delete global settings -->
          <button
            v-if="globalSettingsExist"
            type="button"
            @click="deleteGlobalSettings"
            class="text-xs text-muted-foreground hover:text-destructive transition-colors"
          >
            Delete global defaults
          </button>

          <!-- Delete user settings -->
          <button
            v-if="userSettingsExist"
            type="button"
            @click="deleteUserSettings"
            class="text-xs text-muted-foreground hover:text-destructive transition-colors"
          >
            Delete my user overrides
          </button>

          <!-- Go to global settings -->
          <button
            type="button"
            @click="goToGlobalSettings"
            class="text-xs text-muted-foreground hover:text-foreground transition-colors"
          >
            Configure global settings →
          </button>
        </div>
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
          {{ saving ? 'Saving…' : 'Save Agent Overrides' }}
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
