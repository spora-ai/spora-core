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

// ── Constants ─────────────────────────────────────────────────────────────────

const TIMEZONES: { value: string; label: string }[] = [
  { value: 'UTC', label: 'UTC' },
  { value: 'America/New_York', label: 'New York (ET)' },
  { value: 'America/Chicago', label: 'Chicago (CT)' },
  { value: 'America/Denver', label: 'Denver (MT)' },
  { value: 'America/Los_Angeles', label: 'Los Angeles (PT)' },
  { value: 'America/Anchorage', label: 'Anchorage (AKT)' },
  { value: 'Pacific/Honolulu', label: 'Honolulu (HST)' },
  { value: 'America/Toronto', label: 'Toronto (ET)' },
  { value: 'America/Vancouver', label: 'Vancouver (PT)' },
  { value: 'America/Sao_Paulo', label: 'São Paulo (BRT)' },
  { value: 'Europe/London', label: 'London (GMT/BST)' },
  { value: 'Europe/Paris', label: 'Paris (CET)' },
  { value: 'Europe/Berlin', label: 'Berlin (CET)' },
  { value: 'Europe/Amsterdam', label: 'Amsterdam (CET)' },
  { value: 'Europe/Stockholm', label: 'Stockholm (CET)' },
  { value: 'Europe/Moscow', label: 'Moscow (MSK)' },
  { value: 'Asia/Dubai', label: 'Dubai (GST)' },
  { value: 'Asia/Kolkata', label: 'India (IST)' },
  { value: 'Asia/Singapore', label: 'Singapore (SGT)' },
  { value: 'Asia/Tokyo', label: 'Tokyo (JST)' },
  { value: 'Asia/Shanghai', label: 'Shanghai (CST)' },
  { value: 'Australia/Sydney', label: 'Sydney (AEST)' },
  { value: 'Pacific/Auckland', label: 'Auckland (NZST)' },
]

type Frequency = 'hourly' | 'daily' | 'weekly' | 'monthly' | 'custom'

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
const timezone = ref('UTC')
const rawPrompt = ref('')
const templateId = ref<number | null>(null)
const maxStepsOverride = ref<number | null>(null)

const saving = ref(false)
const error = ref<string | null>(null)

// ── Computed ─────────────────────────────────────────────────────────────────

const isEditing = computed(() => !!props.initialData?.id)
const modalTitle = computed(() => isEditing.value ? 'Edit Schedule' : 'Schedule Run')

// Derive a cron string from frequency + periodic inputs
const computedCron = computed((): string => {
  if (mode.value === 'oneshot') return ''
  if (frequency.value === 'custom') return cronExpression.value.trim()
  if (frequency.value === 'hourly') return '0 * * * *'
  if (frequency.value === 'daily') return '0 9 * * *'
  if (frequency.value === 'weekly') return '0 9 * * 1'
  if (frequency.value === 'monthly') return '0 9 1 * *'
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

// ── Watchers ─────────────────────────────────────────────────────────────────

watch(() => props.modelValue, (open) => {
  if (!open) return
  error.value = null
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
      // Determine frequency
      const c = props.initialData.cron_expression
      if (c === '0 * * * *') frequency.value = 'hourly'
      else if (c === '0 9 * * *') frequency.value = 'daily'
      else if (c === '0 9 * * 1') frequency.value = 'weekly'
      else if (c === '0 9 1 * *') frequency.value = 'monthly'
      else frequency.value = 'custom'
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
  }
})

// ── Submit ────────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  error.value = null
  saving.value = true

  try {
    const payload: Record<string, unknown> = {
      timezone: timezone.value,
      is_active: true,
    }

    if (templateId.value !== null) {
      payload.template_id = templateId.value
    } else if (rawPrompt.value.trim()) {
      payload.raw_prompt = rawPrompt.value.trim()
    } else {
      error.value = 'Please provide a prompt or select a template.'
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
      const result = await api.put<{ data: { scheduled_run: ScheduledRunResource } }>(
        `/agents/${props.agentId}/scheduled-runs/${props.initialData!.id}`,
        payload,
      )
      resource = result.data.scheduled_run
    } else {
      const result = await api.post<{ data: { scheduled_run: ScheduledRunResource } }>(
        `/agents/${props.agentId}/scheduled-runs`,
        payload,
      )
      resource = result.data.scheduled_run
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

      <!-- Prompt / Template -->
      <div class="flex flex-col gap-1.5">
        <label class="text-sm font-medium">Prompt source</label>
        <p class="text-xs text-muted-foreground">Enter a raw prompt or leave blank to use a template (if selected below).</p>
        <textarea
          v-model="rawPrompt"
          rows="3"
          placeholder="What should this agent do? (leave blank to use a template)"
          class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
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
          <option v-for="tz in TIMEZONES" :key="tz.value" :value="tz.value">
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
