<script setup lang="ts">
import { ref, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import type { ToolSchema } from '@/composables/useToolSettings'
import { useToolSettings } from '@/composables/useToolSettings'
import { ApiError, api } from '@/api/client'

const props = defineProps<{
  toolName: string | null
  tool: ToolSchema | null
  agentId: number
}>()

const emit = defineEmits<{
  close: []
}>()

const { getSettings, putSettings } = useToolSettings(props.agentId)

const serverSettings = ref<Record<string, string>>({})
const saving = ref(false)
const error = ref<string | null>(null)
const globalSettingsExist = ref(false)
const loadingSettings = ref(false)

async function loadSettings(toolName: string): Promise<void> {
  loadingSettings.value = true
  error.value = null
  globalSettingsExist.value = false

  // Fetch agent-level settings and global existence check in parallel
  const [settingsResult, globalCheckResult] = await Promise.allSettled([
    getSettings(toolName),
    api.get(`/tools/${encodeURIComponent(toolName)}/settings`),
  ])

  // Handle settings result
  if (settingsResult.status === 'fulfilled') {
    serverSettings.value = settingsResult.value
  } else {
    serverSettings.value = {}
    error.value = settingsResult.reason instanceof ApiError
      ? settingsResult.reason.message
      : 'Failed to load settings.'
  }

  // Handle global check result
  globalSettingsExist.value = globalCheckResult.status === 'fulfilled'

  loadingSettings.value = false
}

watch(
  () => props.toolName,
  (newTool) => {
    if (newTool) loadSettings(newTool)
  },
  { immediate: true },
)

async function onSave(settings: Record<string, string>): Promise<void> {
  saving.value = true
  error.value = null
  try {
    serverSettings.value = await putSettings(props.toolName!, settings, serverSettings.value)
    emit('close')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal
    :modelValue="toolName !== null"
    :title="(tool?.display_name || toolName || '')"
    size="md"
    @update:modelValue="(v) => !v && emit('close')"
    @close="emit('close')"
  >
    <!-- No global config warning -->
    <div
      v-if="toolName && !globalSettingsExist && !loadingSettings"
      class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 px-4 py-3 text-sm text-amber-700 dark:text-amber-300 mb-4"
    >
      <p class="font-medium mb-1">No global configuration found</p>
      <p class="text-xs opacity-80 mb-2">This tool has no global credentials set. You can configure it locally for this agent, or set up global defaults first.</p>
    </div>

    <!-- Loading state -->
    <div v-if="loadingSettings" class="py-8 text-center text-sm text-muted-foreground">
      Loading settings…
    </div>

    <!-- Error state (load failed) -->
    <div v-else-if="error && Object.keys(serverSettings).length === 0" class="py-4 text-xs text-destructive">
      {{ error }}
    </div>

    <ToolSettingsForm
      v-else-if="tool"
      :tool="tool"
      :initialSettings="serverSettings"
      :saving="saving"
      :error="error"
      @save="onSave"
    />
  </Modal>
</template>
