<script setup lang="ts">
/**
 * ToolSettingsForm — renders a complete settings form driven purely by the backend schema.
 *
 * Props:
 *   tool            — ToolSchema with settings_schema[] from GET /tools
 *   initialSettings — Record<key, value> currently saved on the server
 *   saving          — whether a save is in-flight
 *   error           — last save error message
 *
 * Emits:
 *   save(settings: Record<string, string>) — parent calls API and passes back updated serverSettings
 *   saved() — emitted when save succeeds (for flash message in parent)
 *
 * Password handling:
 *   - initialSettings contains "***" for masked (unchanged) password fields
 *   - If user clears a password that was "***", the composable sends "" (clear)
 *   - If user leaves "***" untouched, the field is omitted from the save payload (no-overwrite)
 */
import { ref, computed, watch } from 'vue'
import ToolSettingField from './ToolSettingField.vue'
import { useConfirmDialog } from '@/composables/useConfirmDialog'
import type { ToolSchema } from '@/composables/useToolSettings'

const { confirm } = useConfirmDialog()

const props = defineProps<{
  tool: ToolSchema
  initialSettings: Record<string, string>
  saving: boolean
  error: string | null
  globalDefaults?: Record<string, string>
  canClearToGlobal?: boolean
  mode?: 'global' | 'user'
}>()

const emit = defineEmits<{
  save: [settings: Record<string, string>]
  saved: []
  'clear-to-global': []
}>()

// Local form state: key → value (multi-select stores number[], others store string)
const form = ref<Record<string, string | number[]>>({})

function hasGlobalDefault(key: string): boolean {
  const val = props.globalDefaults?.[key]
  return val !== undefined && val !== ''
}

function globalDefaultValue(key: string): string {
  return props.globalDefaults?.[key] ?? ''
}

function isPasswordField(key: string): boolean {
  return props.tool.settings_schema.find((f) => f.key === key)?.type === 'password' || false
}

// Returns the placeholder value for a field when it inherits from a parent layer.
// Non-password: shows the parent value as hint.
// Password: returns undefined (never show parent password value).
function parentPlaceholder(key: string): string | undefined {
  const parentVal = props.globalDefaults?.[key]
  if (!parentVal) return undefined
  if (isPasswordField(key)) return undefined
  return `e.g. ${parentVal}`
}

// Decode multi-select JSON arrays on load so the form state is `number[]`,
// matching the type the field component expects. Save re-encodes in submit().
function decodeSettings(settings: Record<string, string>): Record<string, string | number[]> {
  const out: Record<string, string | number[]> = {}
  for (const [key, value] of Object.entries(settings)) {
    const field = props.tool.settings_schema.find((f) => f.key === key)
    if (field?.type === 'multi-select') {
      try {
        const parsed = JSON.parse(value)
        out[key] = Array.isArray(parsed) ? parsed.map(Number) : []
      } catch {
        out[key] = []
      }
    } else {
      out[key] = value
    }
  }
  return out
}

// Sync form when initialSettings prop changes (e.g. after save completes)
watch(
  () => props.initialSettings,
  (settings) => {
    form.value = decodeSettings(settings)
  },
  { immediate: true },
)

// Dirty = form differs from initialSettings
// For password fields, "***" in initialSettings means "masked / unchanged" — treat as equal to ''.
// For multi-select, both sides are JSON-encoded for comparison.
const isDirty = computed(() => {
  for (const [key, value] of Object.entries(form.value)) {
    const initial = props.initialSettings[key]
    const encoded = Array.isArray(value) ? JSON.stringify(value) : value
    if (initial === '***') {
      // Password unchanged if user hasn't typed anything new
      if (value !== '' && value !== '***') return true
    } else if (encoded !== initial) {
      return true
    }
  }
  // Also true if form has a new key initialSettings doesn't have
  for (const [key, value] of Object.entries(props.initialSettings)) {
    if (!(key in form.value) && value !== '***') return true
  }
  return false
})

function reset(): void {
  form.value = decodeSettings(props.initialSettings)
}

async function confirmClear(): Promise<void> {
  const isGlobal = props.mode === 'global'
  const message = isGlobal
    ? 'This will delete the global default settings for all fields. Users will no longer have any defaults from this tool.'
    : 'This will delete your overrides and restore the global default settings for all fields.'
  const confirmed = await confirm(message, 'Delete settings?')
  if (confirmed) emit('clear-to-global')
}

async function submit(): Promise<void> {
  // multi-select values are stored as JSON arrays; encode them to strings
  // before the parent (which types payloads as Record<string, string>) sends
  // them to the API.
  const payload: Record<string, string> = {}
  for (const [key, value] of Object.entries(form.value)) {
    payload[key] = Array.isArray(value) ? JSON.stringify(value) : value
  }
  emit('save', payload)
}
</script>

<template>
  <form @submit.prevent="submit" class="flex flex-col gap-5">
    <!-- Fields -->
    <div class="flex flex-col gap-4">
      <div v-for="field in tool.settings_schema" :key="field.key">
        <ToolSettingField
          :modelValue="form[field.key] ?? ''"
          :field="field"
          :customPlaceholder="parentPlaceholder(field.key)"
          @update:modelValue="form[field.key] = Array.isArray($event) ? $event : String($event ?? '')"
        />
        <p v-if="hasGlobalDefault(field.key)" class="text-xs text-muted-foreground mt-1">
          Global default:
          <span v-if="isPasswordField(field.key)" class="font-mono tracking-widest">••••••••</span>
          <span v-else class="font-mono">{{ globalDefaultValue(field.key) }}</span>
        </p>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center justify-between gap-4">
      <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
      <span v-else />
      <div class="flex gap-2">
        <button
          type="button"
          @click="reset"
          :disabled="!isDirty || saving"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors disabled:pointer-events-none disabled:opacity-50"
        >
          Discard changes
        </button>
        <button
          v-if="props.canClearToGlobal"
          type="button"
          @click="confirmClear"
          :disabled="saving"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors disabled:pointer-events-none disabled:opacity-50"
        >
          Delete settings
        </button>
        <button
          type="submit"
          :disabled="!isDirty || saving"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>
  </form>
</template>
