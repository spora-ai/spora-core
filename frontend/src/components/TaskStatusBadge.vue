<script setup lang="ts">
import type { TaskStatus } from '@/types/task'

const props = defineProps<{ status: TaskStatus }>()

const label: Record<TaskStatus, string> = {
  PENDING: 'Pending',
  RUNNING: 'Running',
  COMPLETED: 'Completed',
  FAILED: 'Failed',
  PENDING_APPROVAL: 'Awaiting Approval',
}

const classes: Record<TaskStatus, string> = {
  PENDING: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
  RUNNING: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  COMPLETED: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
  FAILED: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  PENDING_APPROVAL: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
}
</script>

<template>
  <span
    :class="[
      'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
      classes[props.status],
    ]"
  >
    <!-- Pulse dot for active states -->
    <span
      v-if="props.status === 'RUNNING'"
      class="inline-block h-1.5 w-1.5 rounded-full bg-blue-500 animate-pulse"
    />
    <span
      v-else-if="props.status === 'PENDING_APPROVAL'"
      class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"
    />
    {{ label[props.status] }}
  </span>
</template>
