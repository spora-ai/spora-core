/**
 * useScheduleForm — reactive state machine for the 3-step schedule wizard.
 *
 * Returns a fresh form per call. The shell (ScheduleEditor/index.vue) creates
 * one form on mount and passes it down to step sub-components as a prop, so
 * all sub-components share the same state without module-level singletons.
 */
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import type { Frequency } from '@/utils/cron'
import { parseCron } from '@/utils/cron'
import {
  projectCronToFields,
  formatRunAtForInput as formatRunAtForInputPure,
  isRecurring as isRecurringPure,
  defaultWizardFormState,
} from '@/composables/useScheduleFormState'
import {
  buildComputedCron as buildComputedCronPure,
  canSubmitFromStep3 as canSubmitFromStep3Pure,
} from '@/composables/useSchedulePayload'
import { canProceedFromStep1 as canProceedFromStep1Pure } from '@/composables/useScheduleWizard'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import type { ScheduledRunResource } from '@/types/scheduledRun'

const COMMON_ZONE_VALUES = new Set([
  'UTC',
  'America/New_York',
  'America/Los_Angeles',
  'America/Chicago',
  'America/Denver',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'Asia/Tokyo',
  'Asia/Singapore',
  'Asia/Dubai',
  'Asia/Kolkata',
  'Australia/Sydney',
])

export interface ScheduleForm {
  currentStep: Ref<number>
  error: Ref<string | null>
  saving: Ref<boolean>
  mode: Ref<'oneshot' | 'recurring'>
  frequency: Ref<Frequency>
  cronExpression: Ref<string>
  runDate: Ref<string>
  runTime: Ref<string>
  timezone: Ref<string>
  rawPrompt: Ref<string>
  templateId: Ref<number | null>
  newTemplateName: Ref<string>
  showCreateTemplate: Ref<boolean>
  maxStepsOverride: Ref<number | null>
  hourly: Ref<{ interval: number; startHour: number; endHour: number; minute: number }>
  daily: Ref<{ interval: number; time: string }>
  weekly: Ref<{ day: number; time: string }>
  monthly: Ref<{ day: number; time: string }>
  allTimezones: string[]
  commonZoneValues: Set<string>
  computedCron: ComputedRef<string>
  canProceedFromStep1: ComputedRef<boolean>
  canSubmitFromStep3: ComputedRef<boolean>
  canSubmit: ComputedRef<boolean>
  applyParsedCron: (cron: string) => void
  applyInitialData: (data: Partial<ScheduledRunResource>) => void
  onOpen: (initialData: () => Partial<ScheduledRunResource> | null | undefined, agentId: () => number) => Promise<void>
}

export function useScheduleForm(): ScheduleForm {
  const promptTemplatesStore = usePromptTemplatesStore()

  const currentStep = ref(1)
  const error = ref<string | null>(null)
  const saving = ref(false)
  const mode = ref<'oneshot' | 'recurring'>('oneshot')
  const frequency = ref<Frequency>('daily')
  const cronExpression = ref('')
  const runDate = ref('')
  const runTime = ref('')
  const timezone = ref('UTC')
  const rawPrompt = ref('')
  const templateId = ref<number | null>(null)
  const newTemplateName = ref('')
  const showCreateTemplate = ref(false)
  const maxStepsOverride = ref<number | null>(null)
  const hourly = ref({ interval: 1, startHour: 0, endHour: 23, minute: 0 })
  const daily = ref({ interval: 1, time: '09:00' })
  const weekly = ref({ day: 1, time: '09:00' })
  const monthly = ref({ day: 1, time: '09:00' })

  // Intl.supportedValuesOf is widely available; the optional-chained call falls
  // back to a single UTC entry on older runtimes.
  const allTimezones: string[] = (Intl as { supportedValuesOf?: (_t: string) => string[] })
    .supportedValuesOf?.('timeZone') ?? ['UTC']
  const commonZoneValues = COMMON_ZONE_VALUES

  function resetToDefaults(): void {
    const d = defaultWizardFormState()
    mode.value = d.mode
    frequency.value = d.frequency
    cronExpression.value = d.cronExpression
    runDate.value = d.runDate
    runTime.value = d.runTime
    timezone.value = d.timezone
    hourly.value = { ...d.hourly }
    daily.value = { ...d.daily }
    weekly.value = { ...d.weekly }
    monthly.value = { ...d.monthly }
    rawPrompt.value = ''
    templateId.value = null
    newTemplateName.value = ''
    showCreateTemplate.value = false
    maxStepsOverride.value = null
    error.value = null
  }

  function applyParsedCron(cron: string): void {
    const result = parseCron(cron)
    frequency.value = result.frequency
    if (result.fields === null) return
    const fields = projectCronToFields(cron)
    if (fields.hourly) hourly.value = { ...fields.hourly }
    else if (fields.daily) daily.value = { ...fields.daily }
    else if (fields.weekly) weekly.value = { ...fields.weekly }
    else if (fields.monthly) monthly.value = { ...fields.monthly }
  }

  function applyInitialData(data: Partial<ScheduledRunResource>): void {
    mode.value = isRecurringPure(data) ? 'recurring' : 'oneshot'
    timezone.value = data.timezone ?? 'UTC'
    templateId.value = data.template_id ?? null
    rawPrompt.value = data.raw_prompt ?? ''
    maxStepsOverride.value = data.max_steps_override ?? null
    if (data.run_at) {
      const { date, time } = formatRunAtForInputPure(data.run_at, timezone.value)
      runDate.value = date
      runTime.value = time
    }
    if (data.cron_expression) {
      cronExpression.value = data.cron_expression
      applyParsedCron(data.cron_expression)
    }
    if (data.template_id !== null && data.template_id !== undefined) {
      const tmpl = promptTemplatesStore.templates.find((t) => t.id === data.template_id)
      if (tmpl) rawPrompt.value = tmpl.prompt_template
    }
  }

  watch(templateId, (id) => {
    if (id === -1) {
      showCreateTemplate.value = true
      return
    }
    showCreateTemplate.value = false
    if (id === null) return
    const tmpl = promptTemplatesStore.templates.find((t) => t.id === id)
    if (tmpl) rawPrompt.value = tmpl.prompt_template
  })

  const computedCron = computed(() => buildComputedCronPure({
    mode: mode.value,
    frequency: frequency.value,
    cronExpression: cronExpression.value,
    hourly: hourly.value,
    daily: daily.value,
    weekly: weekly.value,
    monthly: monthly.value,
  }))

  const canProceedFromStep1 = computed(() =>
    canProceedFromStep1Pure(templateId.value, newTemplateName.value),
  )

  const canSubmitFromStep3 = computed(() => canSubmitFromStep3Pure({
    mode: mode.value,
    runDate: runDate.value,
    runTime: runTime.value,
    computedCron: computedCron.value,
  }))

  const canSubmit = computed(() => canProceedFromStep1.value && canSubmitFromStep3.value)

  async function onOpen(
    initialData: () => Partial<ScheduledRunResource> | null | undefined,
    agentId: () => number,
  ): Promise<void> {
    error.value = null
    showCreateTemplate.value = false
    newTemplateName.value = ''
    currentStep.value = 1
    saving.value = false

    if (Number.isFinite(agentId())) {
      try {
        await promptTemplatesStore.fetchTemplates(agentId())
      } catch {
        // Templates are optional — don't block the form.
      }
    }

    const data = initialData()
    if (data) applyInitialData(data)
    else resetToDefaults()
  }

  return {
    currentStep,
    error,
    saving,
    mode,
    frequency,
    cronExpression,
    runDate,
    runTime,
    timezone,
    rawPrompt,
    templateId,
    newTemplateName,
    showCreateTemplate,
    maxStepsOverride,
    hourly,
    daily,
    weekly,
    monthly,
    allTimezones,
    commonZoneValues,
    computedCron,
    canProceedFromStep1,
    canSubmitFromStep3,
    canSubmit,
    applyParsedCron,
    applyInitialData,
    onOpen,
  }
}
