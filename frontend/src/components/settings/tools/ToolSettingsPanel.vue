<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError } from '@/api/client'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  tool: ToolSchema
  initialSettings: Record<string, string>
}>()

const emit = defineEmits<{
  saved: [settings: Record<string, string>]
  back: []
}>()

const { putSettings } = useToolSettings()

const serverSettings = ref<Record<string, string>>({ ...props.initialSettings })
const saving = ref(false)
const error = ref<string | null>(null)
const savedFlash = ref(false)
let flashTimer: ReturnType<typeof setTimeout> | null = null
onUnmounted(() => { if (flashTimer) clearTimeout(flashTimer) })

const hasExistingSettings = computed(() =>
  Object.values(serverSettings.value).some((v) => v !== '' && v !== null),
)

const settingsCount = computed(() =>
  Object.values(serverSettings.value).filter((v) => v !== '' && v !== null).length,
)

async function onSave(settings: Record<string, string>): Promise<void> {
  saving.value = true
  error.value = null
  try {
    serverSettings.value = await putSettings(props.tool.tool_name, settings, serverSettings.value)
    savedFlash.value = true
    if (flashTimer) clearTimeout(flashTimer)
    flashTimer = setTimeout(() => { savedFlash.value = false }, 2000)
    emit('saved', serverSettings.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}

function isPasswordField(key: string): boolean {
  return props.tool.settings_schema.find((f) => f.key === key)?.type === 'password' || false
}

function displayValue(key: string, value: string): string {
  if (value === '' || value === null) return '—'
  if (isPasswordField(key)) return '••••••••'
  return value
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

  <!-- Current configuration (collapsible) -->
  <div v-if="hasExistingSettings" class="mb-4">
    <details class="rounded-lg border border-border bg-muted/30">
      <summary class="cursor-pointer px-4 py-2.5 text-sm font-medium text-muted-foreground select-none flex items-center justify-between">
        <span>Current Configuration ({{ settingsCount }} saved)</span>
        <svg class="h-4 w-4 text-muted-foreground/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
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

  <div class="rounded-xl border border-border bg-card p-5">
    <h2 class="text-base font-semibold mb-4">
      {{ tool.display_name || tool.tool_name }}
    </h2>
    <ToolSettingsForm
      :tool="tool"
      :initialSettings="serverSettings"
      :saving="saving"
      :error="error"
      @save="onSave"
    />
  </div>
</template>
