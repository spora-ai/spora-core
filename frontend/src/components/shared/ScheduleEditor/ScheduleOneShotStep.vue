<script setup lang="ts">
/**
 * ScheduleOneShotStep — Step 3 (one-shot branch). Date + time inputs.
 */
import { computed } from 'vue'
import type { ScheduleForm } from '@/composables/useScheduleForm'

const props = defineProps<{ form: ScheduleForm }>()

const runDateModel = computed({
  get: () => props.form.runDate.value,
  set: (v) => { props.form.runDate.value = v ?? '' },
})
const runTimeModel = computed({
  get: () => props.form.runTime.value,
  set: (v) => { props.form.runTime.value = v ?? '' },
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <p class="text-sm text-muted-foreground">
      Set the date and time for this one-time run.
    </p>
    <div class="flex flex-col gap-1.5">
      <label for="schedule-date" class="text-sm font-medium">Date</label>
      <input
        id="schedule-date"
        v-model="runDateModel"
        type="date"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
    <div class="flex flex-col gap-1.5">
      <label for="schedule-time" class="text-sm font-medium">Time</label>
      <input
        id="schedule-time"
        v-model="runTimeModel"
        type="time"
        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
      />
    </div>
  </div>
</template>
