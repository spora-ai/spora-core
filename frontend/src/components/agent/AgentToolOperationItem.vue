<script setup lang="ts">
import Toggle from '@/components/ui/Toggle.vue'

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
          <svg v-if="isAutoApprove" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
          <!-- Lock icon for requires approval -->
          <svg v-else class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
          </svg>
          {{ isAutoApprove ? 'Auto-approve' : 'Requires approval' }}
        </span>
      </div>
      <p class="text-xs text-muted-foreground mt-0.5">{{ description }}</p>
    </div>
    <div class="flex items-center gap-3 shrink-0">
      <!-- Auto-approve toggle (only when enabled) -->
      <label
        v-if="enabled"
        class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer"
        :title="isAutoApprove ? 'Auto-approve is on — click to require approval' : 'Auto-approve is off — click to enable'"
      >
        <Toggle
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
