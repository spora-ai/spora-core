<script setup lang="ts">
/**
 * ScheduledRunsPage — lists all scheduled runs for an agent.
 * Route: /agents/:id/scheduled-runs
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { ScheduledRunResource } from '@/types/scheduledRun'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import SharedScheduleEditor from '@/components/shared/SharedScheduleEditor.vue'
import Toggle from '@/components/ui/Toggle.vue'

const route = useRoute()

const agentId = computed(() => Number(route.params.id))

// ── Data ────────────────────────────────────────────────────────────────────────

interface AgentSummary {
  id: number
  name: string
}

const agent = ref<AgentSummary | null>(null)
const runs = ref<ScheduledRunResource[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

// ── Modal state ───────────────────────────────────────────────────────────────

const showEditor = ref(false)
const editingRun = ref<Partial<ScheduledRunResource> | null>(null)

// ── Lifecycle ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  await loadData()
})

async function loadData(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [agentResult, runsResult] = await Promise.all([
      api.get<{ agent: AgentSummary }>(`/agents/${agentId.value}`),
      api.get<{ scheduled_runs: ScheduledRunResource[] }>(
        `/agents/${agentId.value}/scheduled-runs`,
      ),
    ])
    agent.value = agentResult.agent
    runs.value = runsResult.scheduled_runs
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load scheduled runs.'
  } finally {
    loading.value = false
  }
}

// ── Formatting helpers ───────────────────────────────────────────────────────

function formatSchedule(run: ScheduledRunResource): string {
  if (run.cron_expression) {
    return `Recurring: ${run.cron_expression}`
  }
  if (run.run_at) {
    try {
      return `One-shot: ${new Date(run.run_at).toLocaleString()}`
    } catch {
      return 'One-shot'
    }
  }
  return 'Unknown'
}

function formatTs(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

// ── Actions ───────────────────────────────────────────────────────────────────

async function toggleActive(run: ScheduledRunResource): Promise<void> {
  try {
    const result = await api.put<{ scheduled_run: ScheduledRunResource }>(
      `/agents/${agentId.value}/scheduled-runs/${run.id}`,
      { is_active: !run.is_active },
    )
    const idx = runs.value.findIndex((r) => r.id === run.id)
    if (idx !== -1) runs.value[idx] = result.scheduled_run
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update scheduled run.'
  }
}

async function deleteRun(run: ScheduledRunResource): Promise<void> {
  if (!confirm(`Delete scheduled run "${scheduleName(run)}"?`)) return
  try {
    await api.delete(`/agents/${agentId.value}/scheduled-runs/${run.id}`)
    runs.value = runs.value.filter((r) => r.id !== run.id)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to delete scheduled run.'
  }
}

async function triggerRun(run: ScheduledRunResource): Promise<void> {
  try {
    await api.post<{ scheduled_run: ScheduledRunResource }>(
      `/agents/${agentId.value}/scheduled-runs/${run.id}/trigger`,
    )
    await loadData()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to trigger scheduled run.'
  }
}

function openCreate(): void {
  editingRun.value = null
  showEditor.value = true
}

function openEdit(run: ScheduledRunResource): void {
  editingRun.value = { ...run }
  showEditor.value = true
}

function onSaved(saved: Partial<ScheduledRunResource>): void {
  if (!saved.id) return
  const idx = runs.value.findIndex((r) => r.id === saved.id)
  if (idx !== -1) {
    runs.value[idx] = saved as ScheduledRunResource
  } else {
    runs.value.unshift(saved as ScheduledRunResource)
  }
  showEditor.value = false
  editingRun.value = null
}

function scheduleName(run: ScheduledRunResource): string {
  if (run.template_name) return run.template_name
  if (run.template_id) return `Template #${run.template_id}`
  if (run.raw_prompt) {
    const snippet = run.raw_prompt.length > 40 ? run.raw_prompt.slice(0, 40) + '…' : run.raw_prompt
    return `Custom: ${snippet}`
  }
  return 'Scheduled run'
}
</script>

<template>
  <AgentLayout :agent-id="agentId">

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex-1 flex items-center justify-center text-sm text-destructive px-6">
      {{ error }}
    </div>

    <!-- Empty -->
    <div
      v-else-if="runs.length === 0"
      class="flex-1 flex flex-col items-center justify-center gap-4 px-6 text-center"
    >
      <div class="h-12 w-12 rounded-full bg-muted flex items-center justify-center">
        <svg class="h-6 w-6 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div>
        <p class="text-sm font-medium">No scheduled runs</p>
        <p class="text-xs text-muted-foreground mt-1">Schedule a task to run automatically.</p>
      </div>
      <button
        data-testid="open-schedule-editor-empty"
        @click="openCreate"
        class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        New Schedule
      </button>
    </div>

    <!-- Runs table -->
    <main v-else class="flex-1 overflow-y-auto">

      <!-- Table header -->
      <div class="px-6 py-3 flex items-center justify-between border-b border-border shrink-0">
        <h2 class="text-sm font-semibold">{{ runs.length }} scheduled run{{ runs.length !== 1 ? 's' : '' }}</h2>
        <button
          data-testid="open-schedule-editor-header"
          @click="openCreate"
          class="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          New Schedule
        </button>
      </div>

      <!-- Table -->
      <div data-testid="scheduled-runs-list" class="divide-y divide-border">
        <div
          v-for="run in runs"
          :key="run.id"
          class="px-6 py-4 flex items-center gap-4 hover:bg-muted/40 transition-colors"
        >
          <!-- Schedule description -->
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium truncate">{{ scheduleName(run) }}</p>
            <p class="text-xs text-muted-foreground mt-0.5">
              {{ formatSchedule(run) }}
              <span v-if="run.template_id" class="ml-1 text-primary">template</span>
              <span v-else-if="run.raw_prompt" class="ml-1">custom prompt</span>
            </p>
          </div>

          <!-- Last run -->
          <div class="shrink-0 text-right hidden sm:block">
            <p class="text-xs text-muted-foreground">Last run</p>
            <p class="text-xs font-medium mt-0.5">{{ formatTs(run.last_run_at) }}</p>
          </div>

          <!-- Next run -->
          <div class="shrink-0 text-right hidden md:block">
            <p class="text-xs text-muted-foreground">Next run</p>
            <p class="text-xs font-medium mt-0.5">{{ formatTs(run.next_run_at) }}</p>
          </div>

          <!-- Active toggle -->
          <div class="shrink-0 flex items-center gap-2">
            <span class="text-xs text-muted-foreground hidden sm:inline">Active</span>
            <Toggle
              :modelValue="run.is_active"
              size="sm"
              @update:modelValue="toggleActive(run)"
            />
          </div>

          <!-- Actions -->
          <div class="shrink-0 flex items-center gap-1">
            <!-- Trigger Now -->
            <button
              @click="triggerRun(run)"
              class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
              title="Trigger now"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </button>
            <!-- Edit -->
            <button
              @click="openEdit(run)"
              class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
              title="Edit"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <!-- Delete -->
            <button
              @click="deleteRun(run)"
              class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-destructive hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
              title="Delete"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </main>

    <!-- Schedule Editor Modal -->
    <SharedScheduleEditor
      :modelValue="showEditor"
      :agentId="agentId"
      :initialData="editingRun ?? undefined"
      @update:modelValue="(v) => !v && (showEditor = false)"
      @saved="onSaved"
      @closed="editingRun = null"
    />
  </AgentLayout>
</template>
