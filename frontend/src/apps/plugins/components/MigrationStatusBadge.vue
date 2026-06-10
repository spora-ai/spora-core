<script setup lang="ts">
/**
 * MigrationStatusBadge — color-coded pill surfacing a plugin's migration state.
 *
 *   no_migrations       → gray
 *   up_to_date          → green
 *   pending_migrations  → amber
 */
import { computed } from 'vue'
import type { MigrationStatus } from '../types/plugin'

const props = defineProps<{
  status: MigrationStatus
  pending?: number
}>()

const label = computed<string>(() => {
  if (props.status === 'no_migrations') return 'No migrations'
  if (props.status === 'up_to_date') return 'Up to date'
  return `${props.pending ?? 0} pending`
})

const colorClass = computed<string>(() => {
  if (props.status === 'no_migrations') {
    return 'bg-muted text-muted-foreground border-border'
  }
  if (props.status === 'up_to_date') {
    return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30'
  }
  return 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30'
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="colorClass"
  >
    <span class="w-1.5 h-1.5 rounded-full" :class="status === 'up_to_date' ? 'bg-emerald-500' : status === 'pending_migrations' ? 'bg-amber-500' : 'bg-muted-foreground'" />
    {{ label }}
  </span>
</template>
