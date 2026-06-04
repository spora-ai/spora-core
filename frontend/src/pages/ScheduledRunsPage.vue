<script setup lang="ts">
/**
 * ScheduledRunsPage — lists all scheduled runs for an agent.
 * Route: /agents/:id/scheduled-runs
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useAgentStore } from '@/stores/agent'
import type { ScheduledRunResource } from '@/types/scheduledRun'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import SharedScheduleEditor from '@/components/shared/SharedScheduleEditor.vue'
import { useConfirmDialog } from '@/composables/useConfirmDialog'
import {
  formatScheduleSummary,
  formatRunTimestamp,
  formatScheduleName,
  formatRunCountLabel,
  upsertScheduledRun,
  removeScheduledRun,
} from '@/composables/useScheduledRunsTable'
import Toggle from '@/components/ui/Toggle.vue'
import Icon from '@/components/ui/Icon.vue'

const route = useRoute()
const { confirm } = useConfirmDialog()

const agentId = computed(() => Number(route.params.id))

// Data

interface AgentSummary {
  id: number
  name: string
}

const agent = ref<AgentSummary | null>(null)
const runs = ref<ScheduledRunResource[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

// Modal state

const showEditor = ref(false)
const editingRun = ref<Partial<ScheduledRunResource> | null>(null)

// Lifecycle

onMounted(async () => {
  await loadData()
})

const agentStore = useAgentStore()

async function loadData(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    await agentStore.fetchAgents()
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

// Formatting helpers

function formatSchedule(run: ScheduledRunResource): string {
  return formatScheduleSummary(run)
}

function formatTs(iso: string | null, tz: string): string {
  return formatRunTimestamp(iso, tz)
}

// Actions

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
  if (!await confirm(`Delete scheduled run "${scheduleName(run)}"?`)) return
  try {
    await api.delete(`/agents/${agentId.value}/scheduled-runs/${run.id}`)
    runs.value = removeScheduledRun(runs.value, run.id)
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
  runs.value = upsertScheduledRun(runs.value, saved as ScheduledRunResource)
  showEditor.value = false
  editingRun.value = null
}

function scheduleName(run: ScheduledRunResource): string {
  return formatScheduleName(run)
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
      class="flex-1 flex flex-col items-center justify-center gap-4 px-6 py-16 text-center"
    >
      <div class="h-12 w-12 rounded-full bg-muted flex items-center justify-center">
        <Icon name="clock" class="h-6 w-6 text-muted-foreground" />
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
        <Icon name="plus" class="h-4 w-4" />
        New Schedule
      </button>
    </div>

    <!-- Runs table -->
    <main v-else class="flex-1 overflow-y-auto">

      <!-- Table header -->
      <div class="px-6 py-3 flex items-center justify-between border-b border-border shrink-0">
        <h2 class="text-sm font-semibold">{{ formatRunCountLabel(runs.length) }}</h2>
        <button
          data-testid="open-schedule-editor-header"
          @click="openCreate"
          class="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
        >
          <Icon name="plus" class="h-3.5 w-3.5" />
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
            <p class="text-xs font-medium mt-0.5">{{ formatTs(run.last_run_at, run.timezone) }} <span class="text-muted-foreground text-[10px]">{{ run.timezone }}</span></p>
          </div>

          <!-- Next run -->
          <div class="shrink-0 text-right hidden md:block">
            <p class="text-xs text-muted-foreground">Next run</p>
            <p class="text-xs font-medium mt-0.5">{{ formatTs(run.next_run_at, run.timezone) }} <span class="text-muted-foreground text-[10px]">{{ run.timezone }}</span></p>
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
              <Icon name="lightning" class="h-4 w-4" />
            </button>
            <!-- Edit -->
            <button
              @click="openEdit(run)"
              class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
              title="Edit"
            >
              <Icon name="pencil" class="h-4 w-4" />
            </button>
            <!-- Delete -->
            <button
              @click="deleteRun(run)"
              class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-destructive hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
              title="Delete"
            >
              <Icon name="trash" class="h-4 w-4" />
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
