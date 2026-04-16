<script setup lang="ts">
/**
 * SharedScheduleEditor — reusable modal for creating one-shot or recurring scheduled runs.
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
import { api, ApiError } from '@/api/client'
import type { ScheduledRunResource } from '@/types/scheduledRun'
import { usePromptTemplatesStore } from '@/stores/promptTemplates'
import {
  buildHourlyCron,
  buildDailyCron,
  buildWeeklyCron,
  buildMonthlyCron,
  parseCron,
  DAY_OF_WEEK_OPTIONS,
  type Frequency,
} from '@/utils/cron'

/** Pre-defined variables available in prompt templates, substituted at runtime by the orchestrator. */
const PROMPT_VARIABLES = [
  { token: 'current_date', description: 'ISO date, e.g. 2026-04-15' },
  { token: 'current_time', description: 'ISO time, e.g. 14:30' },
  { token: 'current_datetime', description: 'ISO datetime, e.g. 2026-04-15T14:30' },
  { token: 'agent_name', description: 'Agent display name' },
  { token: 'user_name', description: 'Authenticated user name' },
  { token: 'day_of_week', description: 'Day name, e.g. Wednesday' },
  { token: 'day_of_month', description: 'Day of month, e.g. 15' },
  { token: 'month', description: 'Month name, e.g. April' },
  { token: 'year', description: 'Full year, e.g. 2026' },
] as const

function varToken(token: string): string {
  return `{{${token}}}`
}

// ── Props / Emits ─────────────────────────────────────────────────────────────

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

// ── Timezone list (all IANA zones via Intl, common ones sorted first) ─────────

const allTimezones = (Intl as { supportedValuesOf?: (type: string) => string[] }).supportedValuesOf?.('timeZone') ?? ['UTC']

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

const timezones = computed((): { value: string; label: string }[] => {
  const common = allTimezones
    .filter((tz) => commonZoneValues.has(tz))
    .sort()
    .map((tz) => ({ value: tz, label: tz }))
  const rest = allTimezones
    .filter((tz) => !commonZoneValues.has(tz))
    .sort()
    .map((tz) => ({ value: tz, label: tz }))
  return [...common, ...rest]
})

const FREQUENCY_OPTIONS: { value: Frequency; label: string }[] = [
  { value: 'hourly', label: 'Hourly' },
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'custom', label: 'Custom cron' },
]

// ── Form state ────────────────────────────────────────────────────────────────

const mode = ref<'oneshot' | 'recurring'>('oneshot')
const frequency = ref<Frequency>('daily')
const cronExpression = ref('')
const runDate = ref('')
const runTime = ref('')
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')
const rawPrompt = ref('')
const templateId = ref<number | null>(null)
const maxStepsOverride = ref<number | null>(null)

// Configurable frequency fields
const hourlyInterval = ref(1)       // every X hours (1–23)
const hourlyStartHour = ref(0)       // start hour (0–23)
const hourlyEndHour = ref(23)       // end hour (0–23)
const hourlyMinute = ref(0)          // at minute Y (0–59)

const dailyInterval = ref(1)         // every X days (1–31)
const dailyTime = ref('09:00')      // at HH:MM

const weeklyDay = ref(1)             // day of week 0=Sun … 6=Sat
const weeklyTime = ref('09:00')      // at HH:MM

const monthlyDay = ref(1)            // day of month (1–31)
const monthlyTime = ref('09:00')     // at HH:MM

const saving = ref(false)
const error = ref<string | null>(null)

// Template picker state
const promptTemplatesStore = usePromptTemplatesStore()
const showCreateTemplate = ref(false)
const newTemplateName = ref('')

// ── Computed ─────────────────────────────────────────────────────────────────

const isEditing = computed(() => !!props.initialData?.id)
const modalTitle = computed(() => isEditing.value ? 'Edit Schedule' : 'Schedule Run')

// Derive a cron string from frequency + periodic inputs
const computedCron = computed((): string => {
  if (mode.value === 'oneshot') return ''
  if (frequency.value === 'custom') return cronExpression.value.trim()

  if (frequency.value === 'hourly') {
    return buildHourlyCron({
      interval: hourlyInterval.value,
      startHour: hourlyStartHour.value,
      endHour: hourlyEndHour.value,
      minute: hourlyMinute.value,
    })
  }
  if (frequency.value === 'daily') {
    const [h, m] = dailyTime.value.split(':').map(Number)
    return buildDailyCron({ interval: dailyInterval.value, hour: h, minute: m })
  }
  if (frequency.value === 'weekly') {
    const [h, m] = weeklyTime.value.split(':').map(Number)
    return buildWeeklyCron({ day: weeklyDay.value, hour: h, minute: m })
  }
  if (frequency.value === 'monthly') {
    const [h, m] = monthlyTime.value.split(':').map(Number)
    return buildMonthlyCron({ day: monthlyDay.value, hour: h, minute: m })
  }
  return ''
})

const canSubmit = computed(() => {
  if (mode.value === 'oneshot') {
    return !!(runDate.value && runTime.value)
  }
  return !!computedCron.value
})

// Next 3 run previews
const previewRuns = computed((): string[] => {
  const cron = computedCron.value
  if (!cron) return []
  try {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const Cron = require('cron-parser')
    const intervals: string[] = []
    let date = new Date()
    for (let i = 0; i < 3; i++) {
      const next = Cron.parseCronExpression(cron).getNextDate(date, { tz: timezone.value })
      const d = new Date(next.toDate())
      intervals.push(d.toLocaleString('en-US', {
        timeZone: timezone.value,
        dateStyle: 'medium',
        timeStyle: 'short',
      }))
      date = new Date(d.getTime() + 1000)
    }
    return intervals
  } catch {
    return []
  }
})

// ── Reverse parser ────────────────────────────────────────────────────────────

function applyParsedCron(cron: string): void {
  const result = parseCron(cron)
  frequency.value = result.frequency

  if (result.fields === null) return

  if (result.frequency === 'hourly') {
    const f = result.fields as import('@/utils/cron').HourlyFields
    hourlyMinute.value = f.minute
    hourlyStartHour.value = f.startHour
    hourlyEndHour.value = f.endHour
    hourlyInterval.value = f.interval
  } else if (result.frequency === 'daily') {
    const f = result.fields as import('@/utils/cron').DailyFields
    dailyInterval.value = f.interval
    dailyTime.value = `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`
  } else if (result.frequency === 'weekly') {
    const f = result.fields as import('@/utils/cron').WeeklyFields
    weeklyDay.value = f.day
    weeklyTime.value = `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`
  } else if (result.frequency === 'monthly') {
    const f = result.fields as import('@/utils/cron').MonthlyFields
    monthlyDay.value = f.day
    monthlyTime.value = `${String(f.hour).padStart(2, '0')}:${String(f.minute).padStart(2, '0')}`
  }
}

// ── Watchers ─────────────────────────────────────────────────────────────────

watch(() => props.modelValue, async (open) => {
  if (!open) return
  error.value = null
  showCreateTemplate.value = false
  newTemplateName.value = ''

  // Fetch templates for the picker
  if (Number.isFinite(props.agentId)) {
    try {
      await promptTemplatesStore.fetchTemplates(props.agentId)
    } catch {
      // templates are optional — don't block the form
    }
  }

  if (props.initialData) {
    // Editing mode — restore values
    mode.value = props.initialData.cron_expression ? 'recurring' : 'oneshot'
    timezone.value = props.initialData.timezone ?? 'UTC'
    templateId.value = props.initialData.template_id ?? null
    rawPrompt.value = props.initialData.raw_prompt ?? ''
    maxStepsOverride.value = props.initialData.max_steps_override ?? null
    if (props.initialData.run_at) {
      try {
        const dt = new Date(props.initialData.run_at)
        runDate.value = dt.toISOString().split('T')[0]
        runTime.value = dt.toTimeString().slice(0, 5)
      } catch {
        runDate.value = ''
        runTime.value = ''
      }
    }
    if (props.initialData.cron_expression) {
      cronExpression.value = props.initialData.cron_expression
      applyParsedCron(props.initialData.cron_expression)
    }
  } else {
    // Reset
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
})

// When a template is selected from the dropdown, fill rawPrompt from it
// Skip when id === -1 (create new template) or null
watch(templateId, (id) => {
  if (id === null || id === -1) return
  const tmpl = promptTemplatesStore.templates.find((t) => t.id === id)
  if (tmpl) {
    rawPrompt.value = tmpl.prompt_template
  }
})

// ── Submit ────────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  error.value = null
  saving.value = true

  try {
    let resolvedTemplateId: number | null = templateId.value === -1 ? null : templateId.value

    // If "Create new template…" was selected, create it first
    if (showCreateTemplate.value && newTemplateName.value.trim()) {
      const tmpl = await promptTemplatesStore.createTemplate(props.agentId, {
        name: newTemplateName.value.trim(),
        prompt_template: rawPrompt.value.trim(),
      })
      resolvedTemplateId = tmpl.id
    }

    const payload: Record<string, unknown> = {
      timezone: timezone.value,
      is_active: true,
    }

    if (resolvedTemplateId !== null) {
      payload.template_id = resolvedTemplateId
    } else if (rawPrompt.value.trim()) {
      payload.raw_prompt = rawPrompt.value.trim()
    } else {
      error.value = 'Please provide a prompt or select/create a template.'
      saving.value = false
      return
    }

    if (maxStepsOverride.value !== null) {
      payload.max_steps_override = maxStepsOverride.value
    }

    if (mode.value === 'oneshot') {
      const runAt = `${runDate.value}T${runTime.value}:00`
      payload.run_at = runAt
    } else {
      payload.cron_expression = computedCron.value
    }

    let resource: ScheduledRunResource
    if (isEditing.value) {
      const result = await api.put<{ scheduled_run: ScheduledRunResource }>(
        `/agents/${props.agentId}/scheduled-runs/${props.initialData!.id}`,
        payload,
      )
      resource = result.scheduled_run
    } else {
      const result = await api.post<{ scheduled_run: ScheduledRunResource }>(
        `/agents/${props.agentId}/scheduled-runs`,
        payload,
      )
      resource = result.scheduled_run
    }

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

      <!-- Prompt source: template picker + raw prompt -->
      <div class="flex flex-col gap-3">
        <!-- Template picker -->
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium">Prompt template</label>
          <select
            v-model="templateId"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option :value="null">— No template —</option>
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

        <!-- Inline create template (shown when "+ Create new template…" is selected) -->
        <div
          v-if="templateId === -1"
          class="flex flex-col gap-2 rounded-lg border border-dashed border-border bg-muted/20 p-3"
        >
          <div class="flex items-center gap-2">
            <svg class="h-4 w-4 text-muted-foreground shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
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

        <!-- Raw prompt textarea (disabled when a template is selected) -->
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium flex items-center gap-2">
            <span>Prompt</span>
            <span v-if="templateId !== null && templateId !== -1" class="text-xs font-normal text-muted-foreground bg-muted px-1.5 py-0.5 rounded">from template</span>
          </label>
          <textarea
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

      <!-- One-shot: date + time -->
      <template v-if="mode === 'oneshot'">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium">Date</label>
          <input
            v-model="runDate"
            type="date"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium">Time</label>
          <input
            v-model="runTime"
            type="time"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      </template>

      <!-- Recurring: frequency + cron -->
      <template v-else>
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium">Frequency</label>
          <select
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

        <!-- Custom cron field (hidden unless custom) -->
        <div v-if="frequency === 'custom'" class="flex flex-col gap-1.5">
          <label class="text-sm font-medium">Cron expression</label>
          <input
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

      <!-- Timezone -->
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium">Timezone</label>
        <select
          v-model="timezone"
          class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
        >
          <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
            {{ tz.label }}
          </option>
        </select>
      </div>

    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <button
          @click="close"
          class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          Cancel
        </button>
        <button
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
