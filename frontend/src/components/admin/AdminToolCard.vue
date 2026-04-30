<script setup lang="ts">
import { ref, onUnmounted } from 'vue'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError } from '@/api/client'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  tool: ToolSchema
  settings: Record<string, string>
  saving: boolean
  error: string | null
}>()

const emit = defineEmits<{
  save: [settings: Record<string, string>]
}>()

const { putSettings } = useToolSettings()

const localSettings = ref<Record<string, string>>({ ...props.settings })
const localError = ref<string | null>(null)
const savedFlash = ref(false)
let flashTimer: ReturnType<typeof setTimeout> | null = null
onUnmounted(() => { if (flashTimer) clearTimeout(flashTimer) })

async function onSave(settings: Record<string, string>): Promise<void> {
  localError.value = null
  try {
    const updated = await putSettings(props.tool.tool_name, settings, localSettings.value)
    localSettings.value = updated
    emit('save', updated)
    savedFlash.value = true
    if (flashTimer) clearTimeout(flashTimer)
    flashTimer = setTimeout(() => { savedFlash.value = false }, 2000)
  } catch (e) {
    localError.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  }
}
</script>

<template>
  <div class="rounded-xl border border-border bg-card p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold">
        {{ tool.display_name || tool.tool_name }}
      </h2>
    </div>

    <AlertBanner v-if="savedFlash" type="success" message="Saved!" class="mb-4" />

    <ToolSettingsForm
      :tool="tool"
      :initialSettings="localSettings"
      :saving="saving || props.saving"
      :error="localError || props.error"
      @save="onSave"
    />
  </div>
</template>
