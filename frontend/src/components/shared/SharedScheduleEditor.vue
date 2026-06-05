<script setup lang="ts">
/**
 * SharedScheduleEditor — 3-step wizard for creating one-shot or recurring scheduled runs.
 *
 * Step 1: Template — select existing or create new template (REQUIRED)
 * Step 2: Schedule Type — choose One-shot or Recurring + timezone
 * Step 3: Schedule — configure date/time (one-shot) or frequency+cron (recurring)
 *
 * Props:
 *   modelValue   — v-model boolean to show/hide
 *   agentId      — the agent id (required for saving)
 *   initialData  — optional partial ScheduledRun resource to pre-fill for editing
 *
 * Emits:
 *   update:modelValue
 *   saved     — [savedResource: ScheduledRunResource]
 *   closed
 */
import { ref, computed, watch } from 'vue'
import Modal from '@/components/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import { api, ApiError } from '@/api/client'
import type { ScheduledRunResource } from '@/types/scheduledRun'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import { parseCron, DAY_OF_WEEK_OPTIONS, type Frequency } from '@/utils/cron'
import CronExpression from 'cron-parser'
import {
  SCHEDULE_TOTAL_STEPS,
  SCHEDULE_STEP_LABELS,
  SCHEDULE_FREQUENCY_OPTIONS,
  SCHEDULE_PROMPT_VARIABLES,
  buildTimezoneList,
  defaultTimezone,
  wrapPromptVariable,
  canProceedFromStep1 as checkCanProceedFromStep1,
  buildComputedCron,
  canSubmitFromStep3 as checkCanSubmitFromStep3,
  formatRunAtForInput,
  projectCronToFields,
  buildSchedulePayload,
  isRecurring,
} from '@/composables/useScheduleWizard'

const PROMPT_VARIABLES = SCHEDULE_PROMPT_VARIABLES

function varToken(token: string): string {
  return wrapPromptVariable(token)
}

// Props / Emits

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

// Wizard state

const TOTAL_STEPS = SCHEDULE_TOTAL_STEPS
const currentStep = ref(1)

// Timezone list (all IANA zones via Intl, common ones sorted first)

// eslint-disable-next-line no-unused-vars -- Intl.supportedValuesOf type annotation, type parameter intentionally unused
const allTimezones: string[] = (Intl as { supportedValuesOf?: (_t: string) => string[] }).supportedValuesOf?.('timeZone') ?? ['UTC']

const commonZoneValues = new Set([
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

const timezones = computed(() => buildTimezoneList(allTimezones, commonZoneValues))

const FREQUENCY_OPTIONS = SCHEDULE_FREQUENCY_OPTIONS

// Form state

const mode = ref<'oneshot' | 'recurring'>('oneshot')
const frequency = ref<Frequency>('daily')
const cronExpression = ref('')
const runDate = ref('')
const runTime = ref('')
const timezone = ref(defaultTimezone())
const rawPrompt = ref('')
const templateId = ref<number | null>(null)
const maxStepsOverride = ref<number | null>(null)

// Configurable frequency fields
const hourlyInterval = ref(1)
const hourlyStartHour = ref(0)
const hourlyEndHour = ref(23)
const hourlyMinute = ref(0)

const dailyInterval = ref(1)
const dailyTime = ref('09:00')

const weeklyDay = ref(1)
const weeklyTime = ref('09:00')

const monthlyDay = ref(1)
const monthlyTime = ref('09:00')

const saving = ref(false)
const error = ref<string | null>(null)

// Template picker state
const promptTemplatesStore = usePromptTemplatesStore()
const showCreateTemplate = ref(false)
const newTemplateName = ref('')

// Step 1 validation

const canProceedFromStep1 = computed(() => canProceedFromStep1Check(templateId.value, newTemplateName.value))

// Step 2: nothing extra to validate beyond mode selection

// Step 3 validation

const computedCron = computed(() => buildComputedCronCheck())

const canProceedFromStep3 = computed(() => canSubmitFromStep3Check())

const canSubmit = computed(() => {
  return canProceedFromStep1.value && canProceedFromStep3.value
})

// Next 3 run previews
const previewRuns = computed((): string[] => {
  const cron = computedCron.value
  if (!cron) return []
  try {
    const interval = CronExpression.parse(cron, { tz: timezone.value })
    const intervals: string[] = []
    for (let i = 0; i < 3; i++) {
      const nextDate = interval.next().toDate()
      intervals.push(nextDate.toLocaleString('en-US', {
        timeZone: timezone.value,
        dateStyle: 'medium',
        timeStyle: 'short',
      }))
    }
    return intervals
  } catch {
    return []
  }
})

// Reverse parser

function applyParsedCron(cron: string): void {
  const result = parseCron(cron)
  frequency.value = result.frequency

  if (result.fields === null) return

  const fields = projectCronToFields(cron)
  if (fields.hourly) {
    hourlyMinute.value = fields.hourly.minute
    hourlyStartHour.value = fields.hourly.startHour
    hourlyEndHour.value = fields.hourly.endHour
    hourlyInterval.value = fields.hourly.interval
  } else if (fields.daily) {
    dailyInterval.value = fields.daily.interval
    dailyTime.value = fields.daily.time
  } else if (fields.weekly) {
    weeklyDay.value = fields.weekly.day
    weeklyTime.value = fields.weekly.time
  } else if (fields.monthly) {
    monthlyDay.value = fields.monthly.day
    monthlyTime.value = fields.monthly.time
  }
}

// Watchers

function resetToDefaults(): void {
  mode.value = 'oneshot'
  frequency.value = 'daily'
  cronExpression.value = ''
  runDate.value = ''
  runTime.value = ''
  timezone.value = 'UTC'
  rawPrompt.value = ''
  templateId.value = null
  maxStepsOverride.value = null
  hourlyInterval.value = 1
  hourlyStartHour.value = 0
  hourlyEndHour.value = 23
  hourlyMinute.value = 0
  dailyInterval.value = 1
  dailyTime.value = '09:00'
  weeklyDay.value = 1
  weeklyTime.value = '09:00'
  monthlyDay.value = 1
  monthlyTime.value = '09:00'
}

function formatRunAtForInputLocal(runAt: string, tz: string): { date: string; time: string } {
  return formatRunAtForInput(runAt, tz)
}

function applyInitialData(): void {
  const data = props.initialData
  if (!data) return

  mode.value = isRecurring(data) ? 'recurring' : 'oneshot'
  timezone.value = data.timezone ?? 'UTC'
  templateId.value = data.template_id ?? null
  rawPrompt.value = data.raw_prompt ?? ''
  maxStepsOverride.value = data.max_steps_override ?? null

  if (data.run_at) {
    const { date, time } = formatRunAtForInputLocal(data.run_at, timezone.value)
    runDate.value = date
    runTime.value = time
  }

  if (data.cron_expression) {
    cronExpression.value = data.cron_expression
    applyParsedCron(data.cron_expression)
  }

  // If a template is selected, fill raw_prompt from it (may have been loaded above).
  if (data.template_id !== null) {
    const tmpl = promptTemplatesStore.templates.find((t) => t.id === data.template_id)
    if (tmpl) {
      rawPrompt.value = tmpl.prompt_template
    }
  }
}

async function loadTemplatesForAgent(): Promise<void> {
  if (!Number.isFinite(props.agentId)) return
  try {
    await promptTemplatesStore.fetchTemplates(props.agentId)
  } catch {
    // templates are optional — don't block the form
  }
}

watch(() => props.modelValue, async (open) => {
  if (!open) return
  error.value = null
  showCreateTemplate.value = false
  newTemplateName.value = ''
  currentStep.value = 1

  await loadTemplatesForAgent()

  if (props.initialData) {
    applyInitialData()
  } else {
    resetToDefaults()
  }
})

// When a template is selected from the dropdown, fill rawPrompt from it
watch(templateId, (id) => {
  if (id === -1) {
    showCreateTemplate.value = true
    return
  }
  showCreateTemplate.value = false
  if (id === null) return

  const tmpl = promptTemplatesStore.templates.find((t) => t.id === id)
  if (tmpl) {
    rawPrompt.value = tmpl.prompt_template
  }
})

// Navigation

function nextStep(): void {
  if (currentStep.value < TOTAL_STEPS) {
    currentStep.value++
  }
}

function prevStep(): void {
  if (currentStep.value > 1) {
    currentStep.value--
  }
}

// Submit

function buildSchedulePayloadLocal(): Record<string, unknown> {
  return buildSchedulePayload({
    timezone: timezone.value,
    maxStepsOverride: maxStepsOverride.value,
    mode: mode.value,
    runDate: runDate.value,
    runTime: runTime.value,
    computedCron: computedCron.value,
  })
}

async function resolveTemplateId(): Promise<number | null> {
  let resolvedTemplateId: number | null = templateId.value === -1 ? null : templateId.value

  // If "Create new template…" was selected, create it first
  if (showCreateTemplate.value && newTemplateName.value.trim()) {
    const tmpl = await promptTemplatesStore.createTemplate(props.agentId, {
      name: newTemplateName.value.trim(),
      prompt_template: rawPrompt.value.trim(),
    })
    resolvedTemplateId = tmpl.id
  }

  return resolvedTemplateId
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
  if (!canSubmit.value) return
  error.value = null
  saving.value = true

  try {
    const resolvedTemplateId = await resolveTemplateId()
    const payload = buildSchedulePayloadLocal()

    if (resolvedTemplateId !== null) {
      payload.template_id = resolvedTemplateId
    } else if (rawPrompt.value.trim()) {
      payload.raw_prompt = rawPrompt.value.trim()
    } else {
      error.value = 'Please provide a prompt.'
      saving.value = false
      return
    }

    const resource = await saveSchedule(payload)
    emit('saved', resource)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save schedule.'
  } finally {
    saving.value = false
  }
}

function close(): void {
  emit('update:modelValue', false)
  emit('closed')
}

// Computed helpers

const isEditing = computed(() => !!props.initialData?.id)
const modalTitle = computed(() => isEditing.value ? 'Edit Schedule' : 'Schedule Run')

const stepLabels = SCHEDULE_STEP_LABELS as unknown as string[]

// Wrapper computeds that pass the wizard state to the pure helpers.

function canProceedFromStep1Check(tid: number | null, name: string): boolean {
  return checkCanProceedFromStep1(tid, name)
}

function buildComputedCronCheck(): string {
  return buildComputedCron({
    mode: mode.value,
    frequency: frequency.value,
    cronExpression: cronExpression.value,
    hourly: {
      interval: hourlyInterval.value,
      startHour: hourlyStartHour.value,
      endHour: hourlyEndHour.value,
      minute: hourlyMinute.value,
    },
    daily: { interval: dailyInterval.value, time: dailyTime.value },
    weekly: { day: weeklyDay.value, time: weeklyTime.value },
    monthly: { day: monthlyDay.value, time: monthlyTime.value },
  })
}

function canSubmitFromStep3Check(): boolean {
  return checkCanSubmitFromStep3({
    mode: mode.value,
    runDate: runDate.value,
    runTime: runTime.value,
    computedCron: computedCron.value,
  })
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

      <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>

      <!-- Step indicator -->
      <div class="flex items-center gap-2">
        <div v-for="step in [1, 2, 3]" :key="step" class="flex items-center gap-1.5">
          <div
            class="h-6 w-6 rounded-full flex items-center justify-center text-xs font-medium transition-colors"
            :class="currentStep > step
              ? 'bg-primary text-primary-foreground'
              : currentStep === step
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground'"
          >
            <Icon v-if="currentStep > step" name="check" class="h-3.5 w-3.5" />
            <span v-else>{{ step }}</span>
          </div>
          <span
            class="text-xs font-medium"
            :class="currentStep === step ? 'text-foreground' : 'text-muted-foreground'"
          >{{ stepLabels[step - 1] }}</span>
          <div v-if="step < TOTAL_STEPS" class="flex-1 h-px bg-border min-w-4" />
        </div>
      </div>

      <!-- STEP 1: Template -->

      <div v-show="currentStep === 1" class="flex flex-col gap-4">
        <p class="text-sm text-muted-foreground">
          Choose an existing prompt template or create a new one. A template is required.
        </p>

        <!-- Template picker -->
        <div class="flex flex-col gap-1.5">
          <label for="schedule-template" class="text-sm font-medium">Prompt template</label>
          <select
            id="schedule-template"
            v-model="templateId"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option :value="null">— Select a template —</option>
            <option
              v-for="tmpl in promptTemplatesStore.templates"
              :key="tmpl.id"
              :value="tmpl.id"
            >
              {{ tmpl.name }}
            </option>
            <option :value="-1">+ Create new template…</option>
          </select>
        </div>

        <!-- Inline create template -->
        <div
          v-if="templateId === -1"
          class="flex flex-col gap-2 rounded-lg border border-dashed border-border bg-muted/20 p-3"
        >
          <div class="flex items-center gap-2">
            <Icon name="plus" class="h-4 w-4 text-muted-foreground shrink-0" />
            <span class="text-sm font-medium">New template</span>
          </div>
          <input
            v-model="newTemplateName"
            type="text"
            placeholder="Template name, e.g. Daily Digest"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <p class="text-xs text-muted-foreground">
            The template will be saved and used for this scheduled run.
          </p>
        </div>

        <!-- Prompt textarea (always editable) -->
        <div class="flex flex-col gap-1.5">
          <label for="schedule-prompt" class="text-sm font-medium flex items-center gap-2">
            <span>Prompt</span>
            <span v-if="templateId !== null && templateId !== -1" class="text-xs font-normal text-muted-foreground bg-muted px-1.5 py-0.5 rounded">from template</span>
          </label>
          <textarea
            id="schedule-prompt"
            v-model="rawPrompt"
            rows="5"
            :disabled="templateId !== null && templateId !== -1"
            placeholder="Instructions for the agent"
            class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-60 disabled:cursor-not-allowed"
          />
          <p class="text-xs text-muted-foreground">
            Available runtime variables:
            <code v-for="v in PROMPT_VARIABLES" :key="v.token" class="mx-0.5 px-1 rounded bg-muted text-[10px]">{{ varToken(v.token) }}</code>.
          </p>
        </div>
      </div>

      <!-- STEP 2: Schedule Type -->

      <div v-show="currentStep === 2" class="flex flex-col gap-4">
        <p class="text-sm text-muted-foreground">
          Choose whether this schedule runs once or repeats.
        </p>

        <!-- Mode toggle -->
        <div data-testid="schedule-mode-toggle" class="flex rounded-lg border border-border overflow-hidden text-sm font-medium">
          <button
            type="button"
            data-testid="mode-oneshot"
            @click="mode = 'oneshot'"
            class="flex-1 py-2 text-center transition-colors"
            :class="mode === 'oneshot' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
          >
            One-shot
          </button>
          <button
            type="button"
            data-testid="mode-recurring"
            @click="mode = 'recurring'"
            class="flex-1 py-2 text-center transition-colors"
            :class="mode === 'recurring' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
          >
            Recurring
          </button>
        </div>
      </div>

      <!-- STEP 3: Schedule -->

      <div v-show="currentStep === 3" class="flex flex-col gap-4">

        <!-- One-shot: date + time -->
        <template v-if="mode === 'oneshot'">
          <p class="text-sm text-muted-foreground">
            Set the date and time for this one-time run.
          </p>
          <div class="flex flex-col gap-1.5">
            <label for="schedule-date" class="text-sm font-medium">Date</label>
            <input
              id="schedule-date"
              v-model="runDate"
              type="date"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="schedule-time" class="text-sm font-medium">Time</label>
            <input
              id="schedule-time"
              v-model="runTime"
              type="time"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>
        </template>

        <!-- Recurring: frequency + cron -->
        <template v-else>
          <p class="text-sm text-muted-foreground">
            Configure how often this schedule should repeat.
          </p>
          <div class="flex flex-col gap-1.5">
            <label for="schedule-frequency" class="text-sm font-medium">Frequency</label>
            <select
              id="schedule-frequency"
              v-model="frequency"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option v-for="opt in FREQUENCY_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>

          <!-- Hourly panel -->
          <div v-if="frequency === 'hourly'" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/20 p-4">
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">Every</span>
              <input
                v-model.number="hourlyInterval"
                type="number"
                min="1"
                max="23"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <span class="text-sm text-muted-foreground">hour(s)</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">Starting at hour</span>
              <input
                v-model.number="hourlyStartHour"
                type="number"
                min="0"
                max="23"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <span class="text-sm text-muted-foreground">through</span>
              <input
                v-model.number="hourlyEndHour"
                type="number"
                min="0"
                max="23"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <span class="text-sm text-muted-foreground">at minute</span>
              <input
                v-model.number="hourlyMinute"
                type="number"
                min="0"
                max="59"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <p class="text-xs text-muted-foreground font-mono">
              → {{ computedCron || '—' }}
            </p>
          </div>

          <!-- Daily panel -->
          <div v-if="frequency === 'daily'" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/20 p-4">
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">Every</span>
              <input
                v-model.number="dailyInterval"
                type="number"
                min="1"
                max="31"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <span class="text-sm text-muted-foreground">day(s) at</span>
              <input
                v-model="dailyTime"
                type="time"
                class="w-28 rounded-lg border border-border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <p class="text-xs text-muted-foreground font-mono">
              → {{ computedCron || '—' }}
            </p>
          </div>

          <!-- Weekly panel -->
          <div v-if="frequency === 'weekly'" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/20 p-4">
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">Every</span>
              <select
                v-model.number="weeklyDay"
                class="w-36 rounded-lg border border-border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option v-for="opt in DAY_OF_WEEK_OPTIONS" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <span class="text-sm text-muted-foreground">at</span>
              <input
                v-model="weeklyTime"
                type="time"
                class="w-28 rounded-lg border border-border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <p class="text-xs text-muted-foreground font-mono">
              → {{ computedCron || '—' }}
            </p>
          </div>

          <!-- Monthly panel -->
          <div v-if="frequency === 'monthly'" class="flex flex-col gap-3 rounded-lg border border-border bg-muted/20 p-4">
            <div class="flex items-center gap-2">
              <span class="text-sm text-muted-foreground">Every</span>
              <input
                v-model.number="monthlyDay"
                type="number"
                min="1"
                max="31"
                class="w-16 rounded-lg border border-border bg-background px-2 py-1 text-sm text-center focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <span class="text-sm text-muted-foreground">day of the month at</span>
              <input
                v-model="monthlyTime"
                type="time"
                class="w-28 rounded-lg border border-border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <p class="text-xs text-muted-foreground font-mono">
              → {{ computedCron || '—' }}
            </p>
          </div>

          <!-- Custom cron field -->
          <div v-if="frequency === 'custom'" class="flex flex-col gap-1.5">
            <label for="schedule-cron" class="text-sm font-medium">Cron expression</label>
            <input
              id="schedule-cron"
              v-model="cronExpression"
              type="text"
              placeholder="*/15 * * * *"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono"
            />
            <p class="text-xs text-muted-foreground">
              Format: <span class="font-mono text-[10px]">minute hour day month weekday</span>
            </p>
          </div>

          <!-- Live preview -->
          <div v-if="previewRuns.length > 0" class="rounded-lg border border-border bg-muted/30 px-4 py-3">
            <p class="text-xs font-medium text-muted-foreground mb-2">Next 3 runs</p>
            <ul class="flex flex-col gap-1">
              <li v-for="(run, i) in previewRuns" :key="i" class="text-sm font-mono text-foreground">
                {{ run }}
              </li>
            </ul>
          </div>
          <p v-else-if="computedCron" class="text-xs text-muted-foreground">
            Could not parse cron expression. Check the syntax.
          </p>
        </template>

        <!-- Timezone (common to both modes, shown at the end of Step 3) -->
        <div class="flex flex-col gap-1.5">
          <label for="schedule-timezone" class="text-sm font-medium">Timezone</label>
          <select
            id="schedule-timezone"
            v-model="timezone"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
              {{ tz.label }}
            </option>
          </select>
        </div>

      </div>

    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <!-- Back button -->
        <button
          v-if="currentStep > 1"
          @click="prevStep"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Back
        </button>

        <!-- Cancel -->
        <button
          @click="close"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>

        <!-- Next / Schedule -->
        <button
          v-if="currentStep < TOTAL_STEPS"
          @click="nextStep"
          :disabled="(currentStep === 1 && !canProceedFromStep1)"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          Next
        </button>

        <!-- Schedule (final step) -->
        <button
          v-else
          data-testid="schedule-submit-button"
          @click="submit"
          :disabled="saving || !canSubmit"
          class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : (isEditing ? 'Update' : 'Schedule') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
