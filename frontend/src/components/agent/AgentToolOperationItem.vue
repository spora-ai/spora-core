<script setup lang="ts">
import { computed, useId } from 'vue'
import Toggle from '@/components/ui/Toggle.vue'
import Icon from '@/components/ui/Icon.vue'

const props = defineProps<{
  operationName: string
  description: string
  enabled: boolean
  requiresApproval: boolean  // true = requires approval (lock icon), false = auto-approve (eye icon)
  saving: boolean
}>()

const emit = defineEmits<{
  toggleEnabled: []
  toggleAutoApprove: []
}>()

const isAutoApprove = computed(() => !props.requiresApproval)
const autoApproveToggleId = useId()
</script>
<template>
  <div class="flex items-start gap-3 pl-4 pr-5 py-3 border-t border-border/50">
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2">
        <p class="text-xs font-medium font-mono text-zinc-700 dark:text-zinc-300">{{ operationName }}</p>
        <!-- Approval badge -->
        <span
          v-if="enabled"
          class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
          :class="isAutoApprove
            ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
            : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'"
          :title="isAutoApprove ? 'Auto-approved' : 'Requires approval'"
        >
          <!-- Eye icon for auto-approve -->
          <Icon v-if="isAutoApprove" name="eye" class="h-3 w-3" />
          <!-- Lock icon for requires approval -->
          <Icon v-else name="lock" class="h-3 w-3" />
          {{ isAutoApprove ? 'Auto-approve' : 'Requires approval' }}
        </span>
      </div>
      <p class="text-xs text-muted-foreground mt-0.5">{{ description }}</p>
    </div>
    <div class="flex items-center gap-3 shrink-0">
      <!-- Auto-approve toggle (only when enabled) -->
      <label
        v-if="enabled"
        :for="autoApproveToggleId"
        class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer"
        :title="isAutoApprove ? 'Auto-approve is on — click to require approval' : 'Auto-approve is off — click to enable'"
      >
        <Toggle
          :id="autoApproveToggleId"
          size="sm"
          :model-value="isAutoApprove"
          :disabled="saving"
          @update:model-value="emit('toggleAutoApprove')"
        />
        <span class="text-[11px]">Auto-approve</span>
      </label>
      <!-- Enable toggle -->
      <Toggle
        :model-value="enabled"
        :disabled="saving"
        @update:model-value="emit('toggleEnabled')"
      />
    </div>
  </div>
</template>
