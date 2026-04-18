<script setup lang="ts">
import Toggle from '@/components/ui/Toggle.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  tool: ToolSchema
  enabled: boolean
  autoApproved: boolean
  saving: boolean
  missingRequired?: string[]
}>()

const emit = defineEmits<{
  toggle: []
  toggleAutoApprove: []
  openConfig: []
}>()

const needsConfigWarning = computed(() =>
  props.enabled && (props.missingRequired?.length ?? 0) > 0,
)

import { computed } from 'vue'
</script>

<template>
  <div class="px-5 py-4 flex items-start gap-4">
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2">
        <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
        <!-- Warning badge for missing required settings -->
        <span
          v-if="needsConfigWarning"
          class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400"
          title="Missing required settings"
        >
          <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          Missing config
        </span>
      </div>
      <p
        v-if="tool.settings_schema.length > 0"
        class="text-xs mt-0.5"
        :class="enabled ? 'text-muted-foreground' : 'text-muted-foreground/50'"
      >
        {{ enabled ? 'Has credentials to configure' : 'Enable to configure credentials' }}
      </p>
      <p
        v-if="needsConfigWarning"
        class="text-xs mt-0.5 text-amber-600 dark:text-amber-400"
      >
        This tool may not work until required settings are configured.
      </p>
    </div>
    <div class="flex items-center gap-3 shrink-0">
      <!-- Configure button (only shown when enabled and has settings_schema) -->
      <button
        v-if="enabled && tool.settings_schema.length > 0"
        @click="emit('openConfig')"
        class="inline-flex h-7 items-center justify-center rounded-lg border border-border bg-background px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
      >
        Configure
      </button>
      <!-- Auto-approve toggle (only if enabled) -->
      <label
        v-if="enabled"
        class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer"
        :title="autoApproved ? 'Auto-approve is on' : 'Auto-approve is off'"
      >
        <span>Auto-approve</span>
        <Toggle
          size="sm"
          :model-value="autoApproved"
          :disabled="saving"
          @update:model-value="emit('toggleAutoApprove')"
        />
      </label>
      <!-- Enable/Disable toggle -->
      <Toggle
        :model-value="enabled"
        :disabled="saving"
        @update:model-value="emit('toggle')"
      />
    </div>
  </div>
</template>
