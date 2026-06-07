<script setup lang="ts">
/**
 * AgentToolConfigModal — configures per-agent tool settings.
 *
 * Wires the load + save + delete flows (global/user/override) and delegates
 * rendering to two focused sub-components: AgentToolActiveSettingsPanel for
 * the read-only view, AgentToolOverrideForm for the per-field inputs.
 */
import { ref, computed, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'
import { useToolSettings } from '@/composables/useToolSettings'
import { buildSubmitPayload } from '@/composables/useAgentToolConfig'
import AgentToolActiveSettingsPanel from './AgentToolActiveSettingsPanel.vue'
import AgentToolOverrideForm from './AgentToolOverrideForm.vue'
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

const rawOverride = ref<Record<string, string>>({})
const settingsWithSource = ref<SettingsWithSource>({})
const saving = ref(false)
const error = ref<string | null>(null)
const globalSettingsExist = ref(false)
const userSettings = ref<Record<string, string>>({})
const userSettingsExist = ref(false)
const loadingSettings = ref(false)
const form = ref<Record<string, string>>({})

const hasSchema = computed(() => (props.tool?.settings_schema?.length ?? 0) > 0)

async function loadSettings(toolName: string): Promise<void> {
  loadingSettings.value = true
  error.value = null
  globalSettingsExist.value = false
  userSettingsExist.value = false
  form.value = {}

  const [globalResult, rawResult, sourceResult, userResult] = await Promise.allSettled([
    toolSettings.getGlobalSettings(toolName),
    toolSettings.getRawOverride(toolName),
    toolSettings.getSettingsWithSource(toolName),
    toolSettings.getUserSettings(toolName),
  ])

  if (globalResult.status === 'fulfilled') {
    globalSettingsExist.value = Object.keys(globalResult.value).length > 0
  }
  if (rawResult.status === 'fulfilled') {
    rawOverride.value = rawResult.value
  } else {
    rawOverride.value = {}
  }
  if (sourceResult.status === 'fulfilled') {
    settingsWithSource.value = sourceResult.value
  } else {
    settingsWithSource.value = {}
  }
  if (userResult.status === 'fulfilled') {
    userSettings.value = userResult.value
    userSettingsExist.value = Object.keys(userResult.value).length > 0
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

async function onSave(): Promise<void> {
  if (!props.tool) return
  saving.value = true
  error.value = null
  try {
    const body = buildSubmitPayload(props.tool, form.value)
    await api.put(
      `/agents/${props.agentId}/tools/${encodeURIComponent(props.toolName!)}/override`,
      body,
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
    size="lg"
    @update:modelValue="(v) => !v && emit('close')"
    @close="emit('close')"
  >
    <div v-if="loadingSettings" class="py-8 text-center text-sm text-muted-foreground">
      Loading settings…
    </div>

    <template v-else-if="tool && hasSchema">
      <form @submit.prevent="onSave" class="contents">
        <AgentToolActiveSettingsPanel
          :tool="tool"
          :settings-with-source="settingsWithSource"
        />

        <AgentToolOverrideForm
          :tool="tool"
          :settings-with-source="settingsWithSource"
          :raw-override="rawOverride"
          @update:form="(v) => (form = v)"
          @remove-all="removeAgentOverride"
        />

        <p v-if="error" role="alert" class="text-xs text-destructive mt-4">{{ error }}</p>

        <div class="mt-6 pt-4 border-t border-border">
          <p class="text-xs font-medium text-muted-foreground mb-3">Manage Other Settings</p>
          <div class="flex flex-wrap gap-4">
            <button
              v-if="globalSettingsExist"
              type="button"
              @click="deleteGlobalSettings"
              class="text-xs text-muted-foreground hover:text-destructive transition-colors"
            >
              Delete global defaults
            </button>

            <button
              v-if="userSettingsExist"
              type="button"
              @click="deleteUserSettings"
              class="text-xs text-muted-foreground hover:text-destructive transition-colors"
            >
              Delete my user overrides
            </button>

            <button
              type="button"
              @click="goToGlobalSettings"
              class="text-xs text-muted-foreground hover:text-foreground transition-colors"
            >
              Configure global settings →
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-6">
          <button
            type="button"
            @click="emit('close')"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:opacity-50"
          >
            {{ saving ? 'Saving…' : 'Save Agent Overrides' }}
          </button>
        </div>
      </form>
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
