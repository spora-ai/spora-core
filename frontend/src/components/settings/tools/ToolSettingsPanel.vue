<script setup lang="ts">
import { ref, onUnmounted } from 'vue'
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
