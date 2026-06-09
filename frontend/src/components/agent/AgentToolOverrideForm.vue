<script setup lang="ts">
/**
 * AgentToolOverrideForm — per-field override inputs for a tool.
 *
 * Owns the local `form` ref (key → value) and the `fieldErrors` map, plus
 * the "Remove all agent overrides" action. The parent supplies the schema
 * (from the tool) and the initial values. Emits the latest form snapshot
 * so the parent can build the submit payload.
 */
import { ref, computed, watch } from 'vue'
import ToolSettingField from '@/components/settings/ToolSettingField.vue'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'
import {
  resolveInitialForm,
  diffAgainst,
  getSource as resolveSource,
  getSourceBadgeClass,
  getSourceLabel,
} from '@/composables/useAgentToolConfig'

const props = defineProps<{
  tool: ToolSchema
  settingsWithSource: SettingsWithSource
  rawOverride: Record<string, string>
}>()

const emit = defineEmits<{
  'update:form': [form: Record<string, string>]
  'remove-all': []
}>()

const form = ref<Record<string, string>>(resolveInitialForm(props.settingsWithSource))
const fieldErrors = ref<Record<string, string>>({})

const agentOverridesExist = computed(() => diffAgainst(props.rawOverride).agentOverridesExist)

function getSource(key: string): string {
  return resolveSource(props.settingsWithSource, key)
}

// `immediate: true` makes the watch fire on mount with the current value so
// the parent's `form` ref is populated with the child's initial state. Without
// this, the parent stays at its `ref({})` initializer until the user mutates a
// field, and a "Save with no changes" wipes the override (because the save
// handler reads the parent ref, not the child).
watch(form, (v) => emit('update:form', v), { deep: true, immediate: true })

function onRemoveAll(): void {
  emit('remove-all')
}
</script>

<template>
  <div class="mb-6">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-foreground">Agent-Level Overrides</h3>
      <button
        v-if="agentOverridesExist"
        type="button"
        @click="onRemoveAll"
        class="text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition-colors"
      >
        Remove all agent overrides
      </button>
    </div>

    <p class="text-xs text-muted-foreground mb-4">
      Override settings specifically for this agent. Leave empty to inherit from global/user settings.
    </p>

    <div class="space-y-4">
      <div v-for="field in tool.settings_schema" :key="field.key" class="flex flex-col gap-1.5">
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
        </div>

        <ToolSettingField
          :modelValue="form[field.key] ?? ''"
          :field="field"
          :error="fieldErrors[field.key] ?? null"
          :hideLabel="true"
          @update:modelValue="form[field.key] = String($event ?? '')"
        />
      </div>
    </div>
  </div>
</template>
