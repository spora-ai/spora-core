<script setup lang="ts">
/**
 * AgentToolActiveSettingsPanel — read-only "Currently Active Settings" view.
 *
 * Shows the effective (resolved) value of every field, masked if it's a
 * password, plus a small source badge ("agent" / "user" / "global" /
 * "default"). When the tool exposes LLM-facing fields, those get a
 * dedicated "LLM Capabilities" section.
 */
import { computed, onMounted } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'
import {
  getSource as resolveSource,
  getSourceBadgeClass,
  getSourceLabel,
  hasAnyEffectiveSettings as checkAnyEffective,
  isPasswordField as checkPasswordField,
  maskPasswordValue,
} from '@/composables/useAgentToolConfig'
import { useAgentStore } from '@/stores/agent'

const props = defineProps<{
  tool: ToolSchema
  settingsWithSource: SettingsWithSource
}>()

const agentStore = useAgentStore()

const llmExposedFields = computed(() =>
  (props.tool.settings_schema ?? []).filter((f) => f.expose_to_llm),
)

const hasAnyEffectiveSettings = computed(() => checkAnyEffective(props.settingsWithSource))

onMounted(() => {
  if (agentStore.agents.length === 0) {
    agentStore.fetchAgents()
  }
})

function getSource(key: string): string {
  return resolveSource(props.settingsWithSource, key)
}

function isPasswordField(key: string): boolean {
  return checkPasswordField(props.tool, key)
}

function isFieldEmpty(key: string): boolean {
  const v = props.settingsWithSource[key]?.value
  if (v == null) return true
  if (typeof v === 'string') return v === ''
  if (Array.isArray(v)) return v.length === 0
  return false
}

function agentName(id: number): string {
  return agentStore.agents.find((a) => a.id === id)?.name ?? `#${id}`
}

function getMaskedValue(key: string): string {
  const field = props.tool.settings_schema.find((f) => f.key === key)
  const item = props.settingsWithSource[key]
  const value = item?.value
  if (field?.type === 'multi-select' && Array.isArray(value)) {
    if (value.length === 0) return '—'
    return value.map((id) => agentName(Number(id))).join(', ')
  }
  return maskPasswordValue(value, isPasswordField(key))
}
</script>

<template>
  <div>
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
                v-if="!isFieldEmpty(field.key)"
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

    <div v-if="llmExposedFields.length > 0" class="mb-6">
      <h3 class="text-sm font-medium text-foreground mb-3 flex items-center gap-1.5">
        <Icon name="sparkles" class="h-4 w-4 text-primary" />
        LLM Capabilities
      </h3>
      <p class="text-xs text-muted-foreground mb-3">
        These settings directly influence how the LLM uses this tool.
      </p>
      <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-2.5">
        <div
          v-for="field in llmExposedFields"
          :key="field.key"
          class="flex flex-col sm:flex-row sm:items-start justify-between gap-2 sm:gap-4 text-sm"
        >
          <div class="flex-1 min-w-0">
            <span class="font-medium text-foreground">{{ field.label }}</span>
            <p class="text-xs text-muted-foreground mt-0.5">{{ field.description }}</p>
          </div>
          <span class="shrink-0 font-mono text-xs text-muted-foreground/80 sm:text-right min-w-[80px] break-all sm:max-w-[50%]">
            {{ getMaskedValue(field.key) }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
