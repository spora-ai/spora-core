<script setup lang="ts">
import type { ToolSchema } from '@/composables/useToolSettings'

defineProps<{
  tool: ToolSchema
  enabled: boolean
  autoApproved: boolean
  saving: boolean
}>()

const emit = defineEmits<{
  toggle: []
  toggleAutoApprove: []
  openConfig: []
}>()
</script>

<template>
  <div class="px-5 py-4 flex items-start gap-4">
    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
      <p
        v-if="tool.settings_schema.length > 0"
        class="text-xs mt-0.5"
        :class="enabled ? 'text-muted-foreground' : 'text-muted-foreground/50'"
      >
        {{ enabled ? 'Has credentials to configure' : 'Enable to configure credentials' }}
      </p>
    </div>
    <div class="flex items-center gap-3 shrink-0">
      <!-- Configure button (only for enabled tools with settings_schema) -->
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
        <button
          @click="emit('toggleAutoApprove')"
          :disabled="saving"
          class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
          :class="autoApproved ? 'bg-primary' : 'bg-muted'"
        >
          <span
            class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform"
            :class="autoApproved ? 'translate-x-4' : 'translate-x-0.5'"
          />
        </button>
      </label>
      <!-- Enable/Disable toggle -->
      <button
        @click="emit('toggle')"
        :disabled="saving"
        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
        :class="enabled ? 'bg-primary' : 'bg-muted'"
      >
        <span
          class="inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
          :class="enabled ? 'translate-x-6' : 'translate-x-1'"
        />
      </button>
    </div>
  </div>
</template>
