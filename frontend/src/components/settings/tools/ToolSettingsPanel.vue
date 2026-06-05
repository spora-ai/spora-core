<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError } from '@/api/client'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema } from '@/composables/useToolSettings'
import {
  displayValue as formatDisplayValue,
  diffFromGlobalDefaults,
  hasExistingSettings as checkHasExisting,
  countNonEmptySettings as countNonEmpty,
  llmExposedFields as filterLlmExposed,
  resolveMode,
} from '@/composables/useToolSettingsPanel'

const props = defineProps<{
  tool: ToolSchema
  initialSettings?: Record<string, string>
  globalDefaults?: Record<string, string>
  mode?: 'global' | 'user'
}>()

const emit = defineEmits<{
  saved: [settings: Record<string, string>]
  back: []
}>()

const { getGlobalSettings, getUserSettings, putUserSettings, putSettings, deleteSettings, deleteUserSettings } = useToolSettings()

const mode = computed(() => resolveMode(props.mode))

const serverSettings = ref<Record<string, string>>({ ...props.initialSettings })
const saving = ref(false)
const clearing = ref(false)
const error = ref<string | null>(null)
const savedFlash = ref(false)
const clearedFlash = ref(false)
let savedTimer: ReturnType<typeof setTimeout> | null = null
let clearedTimer: ReturnType<typeof setTimeout> | null = null
onUnmounted(() => { if (savedTimer) clearTimeout(savedTimer); if (clearedTimer) clearTimeout(clearedTimer) })

const hasExistingSettings = computed(() => checkHasExisting(serverSettings.value))

const llmExposedFields = computed(() => filterLlmExposed(props.tool))

const settingsCount = computed(() => countNonEmpty(serverSettings.value))

async function loadSettings(): Promise<void> {
  const id = ++loadId
  let result: Record<string, string>
  if (mode.value === 'user') {
    result = await getUserSettings(props.tool.tool_name)
  } else {
    result = await getGlobalSettings(props.tool.tool_name)
  }
  // Ignore if a newer request has already completed
  if (id !== loadId) return
  serverSettings.value = result
}

let loadId = 0
onMounted(loadSettings)

watch(() => props.tool.tool_name, loadSettings)

async function onSave(settings: Record<string, string>): Promise<void> {
  saving.value = true
  error.value = null
  try {
    if (mode.value === 'user') {
      // Diff against global defaults: only send values that differ from global
      const toSave = diffFromGlobalDefaults(settings, props.globalDefaults)
      serverSettings.value = await putUserSettings(props.tool.tool_name, toSave)
    } else {
      serverSettings.value = await putSettings(props.tool.tool_name, settings, serverSettings.value)
    }
    savedFlash.value = true
    if (savedTimer) clearTimeout(savedTimer)
    savedTimer = setTimeout(() => { savedFlash.value = false }, 2000)
    emit('saved', serverSettings.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}

async function onClearToGlobal(): Promise<void> {
  clearing.value = true
  error.value = null
  try {
    if (mode.value === 'user') {
      await deleteUserSettings(props.tool.tool_name)
      serverSettings.value = await getUserSettings(props.tool.tool_name)
    } else {
      await deleteSettings(props.tool.tool_name)
      serverSettings.value = await getGlobalSettings(props.tool.tool_name)
    }
    clearedFlash.value = true
    if (clearedTimer) clearTimeout(clearedTimer)
    clearedTimer = setTimeout(() => { clearedFlash.value = false }, 2000)
    emit('saved', serverSettings.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to reset settings.'
  } finally {
    clearing.value = false
  }
}

function displayValue(key: string, value: string): string {
  return formatDisplayValue(props.tool, key, value)
}
</script>

<template>
  <button
    type="button"
    @click="emit('back')"
    class="mb-3 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
  >
    ← All tools
  </button>

  <AlertBanner v-if="savedFlash" type="success" message="Settings saved." class="mb-4" />
  <AlertBanner v-else-if="clearedFlash" type="success" message="Settings deleted." class="mb-4" />

  <!-- Current configuration (collapsible) -->
  <div v-if="hasExistingSettings" class="mb-4">
    <details class="rounded-lg border border-border bg-muted/30">
      <summary class="cursor-pointer px-4 py-2.5 text-sm font-medium text-muted-foreground select-none flex items-center justify-between">
        <span>Current Configuration ({{ settingsCount }} saved)</span>
        <Icon name="chevron-down" class="h-4 w-4 text-muted-foreground/60" />
      </summary>
      <div class="px-4 pb-3 pt-2 space-y-2">
        <div
          v-for="field in tool.settings_schema"
          :key="field.key"
          class="flex items-center justify-between text-xs"
        >
          <span class="text-muted-foreground">{{ field.label }}:</span>
          <span class="font-mono text-muted-foreground/80">
            {{ displayValue(field.key, serverSettings[field.key] ?? '') }}
          </span>
        </div>
      </div>
    </details>
  </div>

  <!-- LLM Capabilities -->
  <div v-if="llmExposedFields.length > 0" class="mb-4">
    <div class="rounded-lg border border-primary/20 bg-primary/5 p-4">
      <div class="flex items-center gap-1.5 mb-2">
        <Icon name="sparkles" class="h-4 w-4 text-primary" />
        <h3 class="text-sm font-medium text-foreground">LLM Capabilities</h3>
      </div>
      <p class="text-xs text-muted-foreground mb-3">
        These settings directly influence how the LLM uses this tool.
      </p>
      <div class="space-y-2">
        <div
          v-for="field in llmExposedFields"
          :key="field.key"
          class="flex items-start justify-between gap-4 text-sm"
        >
          <div class="flex-1">
            <span class="font-medium text-foreground">{{ field.label }}</span>
            <p class="text-xs text-muted-foreground mt-0.5">{{ field.description }}</p>
          </div>
          <span class="shrink-0 font-mono text-xs text-muted-foreground/80 text-right min-w-[80px]">
            {{ displayValue(field.key, serverSettings[field.key] ?? '') }}
          </span>
        </div>
      </div>
    </div>
  </div>

  <div class="rounded-xl border border-border bg-card p-5">
    <h2 class="text-base font-semibold mb-4">
      {{ tool.display_name || tool.tool_name }}
    </h2>
    <ToolSettingsForm
      :tool="tool"
      :initialSettings="serverSettings"
      :globalDefaults="globalDefaults"
      :canClearToGlobal="mode === 'user' || mode === 'global'"
      :saving="saving || clearing"
      :error="error"
      :mode="mode"
      @save="onSave"
      @clear-to-global="onClearToGlobal"
    />
  </div>
</template>
