<script setup lang="ts">
/**
 * SharedScheduleEditor — 3-step wizard shell.
 *
 * Wires the `<Modal>` chrome, the step navigation, and the submit flow.
 * Creates one `useScheduleForm` instance on mount and passes it down to
 * every step sub-component so they all share the same reactive state.
 *
 * Step 1: Template — `ScheduleTemplateStep`
 * Step 2: Schedule Type — `ScheduleTypeStep`
 * Step 3: Schedule — `ScheduleOneShotStep` or `ScheduleRecurringStep`
 */
import { computed, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { useScheduleForm } from '@/composables/useScheduleForm'
import { buildSchedulePayload } from '@/composables/useSchedulePayload'
import { SCHEDULE_TOTAL_STEPS } from '@/composables/useScheduleWizard'
import type { ScheduledRunResource } from '@/types/scheduledRun'
import Modal from '@/components/Modal.vue'
import ScheduleStepper from './ScheduleStepper.vue'
import ScheduleTemplateStep from './ScheduleTemplateStep.vue'
import ScheduleTypeStep from './ScheduleTypeStep.vue'
import ScheduleOneShotStep from './ScheduleOneShotStep.vue'
import ScheduleRecurringStep from './ScheduleRecurringStep.vue'

const props = defineProps<{
  modelValue: boolean
  agentId: number
  initialData?: Partial<ScheduledRunResource> | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [resource: ScheduledRunResource]
  closed: []
}>()

const form = useScheduleForm()
const promptTemplatesStore = usePromptTemplatesStore()

const isEditing = computed(() => !!props.initialData?.id)
const modalTitle = computed(() => (isEditing.value ? 'Edit Schedule' : 'Schedule Run'))

watch(() => props.modelValue, async (open) => {
  if (!open) return
  await form.onOpen(
    () => props.initialData,
    () => props.agentId,
  )
}, { immediate: true })

function nextStep(): void {
  if (form.currentStep.value < SCHEDULE_TOTAL_STEPS) form.currentStep.value++
}
function prevStep(): void {
  if (form.currentStep.value > 1) form.currentStep.value--
}

async function resolveTemplateId(): Promise<number | null> {
  let resolved: number | null = form.templateId.value === -1 ? null : form.templateId.value
  if (form.showCreateTemplate.value && form.newTemplateName.value.trim()) {
    const tmpl = await promptTemplatesStore.createTemplate(props.agentId, {
      name: form.newTemplateName.value.trim(),
      prompt_template: form.rawPrompt.value.trim(),
    })
    resolved = tmpl.id
  }
  return resolved
}

async function saveSchedule(payload: Record<string, unknown>): Promise<ScheduledRunResource> {
  if (props.initialData?.id) {
    const result = await api.put<{ scheduled_run: ScheduledRunResource }>(
      `/agents/${props.agentId}/scheduled-runs/${props.initialData.id}`,
      payload,
    )
    return result.scheduled_run
  }
  const result = await api.post<{ scheduled_run: ScheduledRunResource }>(
    `/agents/${props.agentId}/scheduled-runs`,
    payload,
  )
  return result.scheduled_run
}

async function submit(): Promise<void> {
  if (!form.canSubmit.value) return
  form.error.value = null
  form.saving.value = true
  try {
    const resolvedTemplateId = await resolveTemplateId()
    const payload = buildSchedulePayload({
      timezone: form.timezone.value,
      maxStepsOverride: form.maxStepsOverride.value,
      mode: form.mode.value,
      runDate: form.runDate.value,
      runTime: form.runTime.value,
      computedCron: form.computedCron.value,
    })
    if (resolvedTemplateId !== null) {
      payload.template_id = resolvedTemplateId
    } else if (form.rawPrompt.value.trim()) {
      payload.raw_prompt = form.rawPrompt.value.trim()
    } else {
      form.error.value = 'Please provide a prompt.'
      form.saving.value = false
      return
    }
    const resource = await saveSchedule(payload)
    emit('saved', resource)
    emit('update:modelValue', false)
  } catch (e) {
    form.error.value = e instanceof ApiError ? e.message : 'Failed to save schedule.'
  } finally {
    form.saving.value = false
  }
}

function close(): void {
  emit('update:modelValue', false)
  emit('closed')
}
</script>

<template>
  <Modal
    :modelValue="modelValue"
    :title="modalTitle"
    size="md"
    @update:modelValue="(v) => !v && close()"
    @close="close"
  >
    <div class="flex flex-col gap-5">
      <p v-if="form.error.value" role="alert" class="text-xs text-destructive">{{ form.error.value }}</p>

      <ScheduleStepper :form="form" />

      <ScheduleTemplateStep v-show="form.currentStep.value === 1" :form="form" />
      <ScheduleTypeStep v-show="form.currentStep.value === 2" :form="form" />
      <ScheduleOneShotStep v-if="form.currentStep.value === 3 && form.mode.value === 'oneshot'" :form="form" />
      <ScheduleRecurringStep v-if="form.currentStep.value === 3 && form.mode.value === 'recurring'" :form="form" />
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          v-if="form.currentStep.value > 1"
          @click="prevStep"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >Back</button>

        <button
          @click="close"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >Cancel</button>

        <button
          v-if="form.currentStep.value < SCHEDULE_TOTAL_STEPS"
          @click="nextStep"
          :disabled="form.currentStep.value === 1 && !form.canProceedFromStep1.value"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >Next</button>

        <button
          v-else
          data-testid="schedule-submit-button"
          @click="submit"
          :disabled="form.saving.value || !form.canSubmit.value"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >{{ form.saving.value ? 'Saving…' : (isEditing ? 'Update' : 'Schedule') }}</button>
      </div>
    </template>
  </Modal>
</template>
