<script setup lang="ts">
/**
 * ScheduleTypeStep — Step 2. One-shot vs Recurring toggle.
 */
import type { ScheduleForm } from '@/composables/useScheduleForm'

defineProps<{ form: ScheduleForm }>()
</script>

<template>
  <div class="flex flex-col gap-4">
    <p class="text-sm text-muted-foreground">
      Choose whether this schedule runs once or repeats.
    </p>

    <div data-testid="schedule-mode-toggle" class="flex rounded-lg border border-border overflow-hidden text-sm font-medium">
      <button
        type="button"
        data-testid="mode-oneshot"
        @click="form.mode.value = 'oneshot'"
        class="flex-1 py-2 text-center transition-colors"
        :class="form.mode.value === 'oneshot' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
      >
        One-shot
      </button>
      <button
        type="button"
        data-testid="mode-recurring"
        @click="form.mode.value = 'recurring'"
        class="flex-1 py-2 text-center transition-colors"
        :class="form.mode.value === 'recurring' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
      >
        Recurring
      </button>
    </div>
  </div>
</template>
