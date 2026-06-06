<script setup lang="ts">
/**
 * ScheduleStepper — top-of-modal step indicator (1, 2, 3 with labels).
 * Pure presentation; reads `form.currentStep`, writes nothing.
 */
import { SCHEDULE_TOTAL_STEPS, SCHEDULE_STEP_LABELS } from '@/composables/useScheduleWizard'
import type { ScheduleForm } from '@/composables/useScheduleForm'
import Icon from '@/components/ui/Icon.vue'

defineProps<{ form: ScheduleForm }>()

const stepLabels = SCHEDULE_STEP_LABELS as unknown as string[]
</script>

<template>
  <div class="flex items-center gap-2">
    <div v-for="step in SCHEDULE_TOTAL_STEPS" :key="step" class="flex items-center gap-1.5">
      <div
        class="h-6 w-6 rounded-full flex items-center justify-center text-xs font-medium transition-colors"
        :class="form.currentStep.value > step
          ? 'bg-primary text-primary-foreground'
          : form.currentStep.value === step
            ? 'bg-primary text-primary-foreground'
            : 'bg-muted text-muted-foreground'"
      >
        <Icon v-if="form.currentStep.value > step" name="check" class="h-3.5 w-3.5" />
        <span v-else>{{ step }}</span>
      </div>
      <span
        class="text-xs font-medium"
        :class="form.currentStep.value === step ? 'text-foreground' : 'text-muted-foreground'"
      >{{ stepLabels[step - 1] }}</span>
      <div v-if="step < SCHEDULE_TOTAL_STEPS" class="flex-1 h-px bg-border min-w-4" />
    </div>
  </div>
</template>
