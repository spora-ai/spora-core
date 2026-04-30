<script setup lang="ts">
import Toggle from '@/components/ui/Toggle.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  tool: ToolSchema & { operations?: ToolOperationSchema[] }
  enabled: boolean
  autoApproved: boolean
  saving: boolean
  missingRequired?: string[]
  operationStates?: Record<string, { enabled: boolean; requiresApproval: boolean }>
}>()

const emit = defineEmits<{
  toggle: []
  toggleAutoApprove: []
  openConfig: []
  toggleOperationEnabled: [operationName: string]
  toggleOperationAutoApprove: [operationName: string]
}>()

export interface ToolOperationSchema {
  name: string
  description: string
  enabledByDefault: boolean
  requiresApprovalByDefault: boolean
}

const needsConfigWarning = computed(() =>
  props.enabled && (props.missingRequired?.length ?? 0) > 0,
)

const hasOperations = computed(() =>
  (props.tool.operations?.length ?? 0) > 0,
)

import { computed } from 'vue'
</script>

<template>
  <div class="px-5 py-4 flex flex-col gap-3">
    <div class="flex items-start gap-4">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
          <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
          <!-- Warning badge for missing required settings -->
          <span
            v-if="needsConfigWarning"
            class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400"
            title="Missing required settings"
          >
            <Icon name="warning" class="h-3 w-3" />
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
        <!-- Auto-approve toggle (only if enabled and no operations) -->
        <label
          v-if="enabled && !hasOperations"
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

    <!-- Operations list (shown when tool has operations) -->
    <div v-if="hasOperations && enabled" class="flex flex-col divide-y divide-border/50 border border-border/50 rounded-lg overflow-hidden">
      <div
        v-for="op in tool.operations"
        :key="op.name"
        class="flex items-start gap-3 pl-4 pr-5 py-3 bg-muted/10"
      >
        <div class="flex items-center shrink-0">
          <!-- Enable/Disable toggle per operation -->
          <Toggle
            size="sm"
            :model-value="operationStates?.[op.name]?.enabled ?? op.enabledByDefault"
            :disabled="saving"
            @update:model-value="emit('toggleOperationEnabled', op.name)"
          />
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-xs font-medium font-mono text-zinc-700 dark:text-zinc-300">{{ op.name }}</p>
            <!-- Badge: eye = auto-approve, lock = requires approval -->
            <span
              class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
              :class="(operationStates?.[op.name]?.requiresApproval ?? op.requiresApprovalByDefault) === false
                ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
                : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'"
            >
              <!-- Eye for auto-approve, lock for requires approval -->
              <Icon v-if="(operationStates?.[op.name]?.requiresApproval ?? op.requiresApprovalByDefault) === false" name="eye" class="h-3 w-3" />
              <Icon v-else name="lock" class="h-3 w-3" />
              {{ (operationStates?.[op.name]?.requiresApproval ?? op.requiresApprovalByDefault) === false ? 'Auto-approve' : 'Requires approval' }}
            </span>
          </div>
          <p class="text-xs text-muted-foreground mt-0.5">{{ op.description }}</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <!-- Per-operation auto-approve toggle -->
          <span class="text-[11px] text-muted-foreground">Auto-approve</span>
          <Toggle
            size="sm"
            :model-value="(operationStates?.[op.name]?.requiresApproval ?? op.requiresApprovalByDefault) === false"
            :disabled="saving || !(operationStates?.[op.name]?.enabled ?? op.enabledByDefault)"
            @update:model-value="emit('toggleOperationAutoApprove', op.name)"
          />
        </div>
      </div>
    </div>
  </div>
</template>
