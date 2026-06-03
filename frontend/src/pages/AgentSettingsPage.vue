<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useLlmPreferencesStore } from '@/stores/llmPreferencesStore'
import { useToolSettings } from '@/composables/useToolSettings'
import AgentLayout from '@/components/layout/AgentLayout.vue'
import type { ToolSchema, ToolStatus } from '@/composables/useToolSettings'
import AgentLlmConfigModal from '@/components/agent/AgentLlmConfigModal.vue'
import AgentToolConfigModal from '@/components/agent/AgentToolConfigModal.vue'
import AgentToolListItem from '@/components/agent/AgentToolListItem.vue'
import EnableWarningModal from '@/components/agent/EnableWarningModal.vue'
import type { LLMDriverInfo } from '@/types/llmConfig'
import { ApiError, api } from '@/api/client'
import Icon from '@/components/ui/Icon.vue'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const llmConfigsStore = useLlmConfigsStore()
const preferenceStore = useLlmPreferencesStore()

const agentId = computed(() => Number(route.params.id))


const toolRegistry = ref<ToolSchema[]>([])
const loadingTools = ref(false)


const toolStatusMap = ref<Record<string, ToolStatus>>({})
const toolSettings = useToolSettings(agentId.value)


interface LLMConfigResource {
  id: number
  name: string
  driver_display_name: string
  driver_class: string
  is_default: boolean
  is_global: boolean
}

const llmConfigs = ref<LLMConfigResource[]>([])
const llmDrivers = ref<LLMDriverInfo[]>([])

const llmSettingsForm = ref({
  llm_driver_config_id: null as number | null,
})
const savingLlmSettings = ref(false)
const llmSettingsError = ref<string | null>(null)
const llmSettingsSaved = ref(false)

// LLM config creation
const showLlmCreate = ref(false)

function onLlmCreated(config: LLMConfigResource): void {
  llmConfigs.value.push(config)
  llmSettingsForm.value.llm_driver_config_id = config.id
}

function configLabel(config: LLMConfigResource): string {
  return config.is_global ? `${config.name} (${config.driver_display_name}) — Global` : `${config.name} (${config.driver_display_name})`
}


const identityForm = ref({
  name: '',
  description: '',
  system_prompt: '',
  max_steps: 10,
  allow_continuation: true,
  retry_after_minutes: 0,
  max_retries: 0,
})
const savingIdentity = ref(false)
const identityError = ref<string | null>(null)
const identitySaved = ref(false)


// Track enabled tool names
const enabledToolNames = ref<Set<string>>(new Set())

// Per-operation saving states (separate keys so operations don't disable each other)
const savingTool = ref<Record<string, boolean>>({})
const savingOperation = ref<Record<string, boolean>>({})

// Per-operation effective states: Record<toolName, Record<operationName, { enabled, requiresApproval }>>
const operationStates = ref<Record<string, Record<string, { enabled: boolean; requiresApproval: boolean }>>>({})

const toolsError = ref<string | null>(null)

// Tool configuration modal
const configuringTool = ref<string | null>(null)

// Pre-activation warning modal
const pendingEnableTool = ref<string | null>(null)

// Collapsed state per category
const collapsedCategories = ref<Record<string, boolean>>({})


function toLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

const toolsByCategory = computed(() => {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of toolRegistry.value) {
    const cat = (tool as any).category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
})

const sortedCategories = computed(() =>
  Object.keys(toolsByCategory.value).sort((a, b) => toLabel(a).localeCompare(toLabel(b))),
)

function showEnableWarning(toolName: string): void {
  pendingEnableTool.value = toolName
}

function configuringToolSchema(): ToolSchema | null {
  return toolRegistry.value.find((t) => t.tool_name === configuringTool.value) ?? null
}


const deleting = ref(false)
const confirmDeleteName = ref('')


onMounted(async () => {
  await Promise.all([
    agentStore.fetchAgents(),
    agentStore.fetchAgent(agentId.value),
    llmConfigsStore.ensure(),
    preferenceStore.loadPreference(),
  ])

  const agent = agentStore.currentAgent!
  identityForm.value = {
    name: agent.name,
    description: agent.description ?? '',
    system_prompt: agent.system_prompt ?? '',
    max_steps: agent.max_steps ?? 10,
    allow_continuation: agent.allow_continuation !== false,
    retry_after_minutes: agent.retry_after_minutes ?? 0,
    max_retries: agent.max_retries ?? 0,
  }
  llmSettingsForm.value = {
    llm_driver_config_id: agent.llm_driver_config_id ?? null,
  }

  // Seed enabled tools state
  enabledToolNames.value = new Set(agent.tools.map((t) => t.tool_name))

  // Fetch tool registry, LLM configs, and LLM drivers in parallel
  const [toolsResult, configsResult, driversResult] = await Promise.all([
    api.get<{ tools: ToolSchema[] }>('/tools'),
    api.get<{ configs: LLMConfigResource[] }>('/llm-configs'),
    api.get<{ drivers: LLMDriverInfo[] }>('/llm-drivers'),
  ])
  toolRegistry.value = toolsResult.tools
  llmConfigs.value = configsResult.configs
  llmDrivers.value = driversResult.drivers

  // Fetch tool status for all tools in a single batch request
  const allStatuses = await toolSettings.getAllToolStatuses()
  toolStatusMap.value = allStatuses

  // Sync enabledToolNames with the authoritative is_enabled status from the API.
  // agent.tools is the list of associated tools, but toolStatusMap tells us which
  // ones are actually enabled (e.g. AgentMemoryTool may be associated but is_enabled=false).
  for (const tool of agent.tools) {
    const status = allStatuses[tool.tool_name]
    if (status) {
      if (status.is_enabled) {
        enabledToolNames.value.add(tool.tool_name)
      } else {
        enabledToolNames.value.delete(tool.tool_name)
      }
    }
  }

  // Load operation overrides for all enabled tools that have operations
  await loadOperationOverrides()
})


async function saveIdentity(): Promise<void> {
  identityError.value = null
  identitySaved.value = false
  savingIdentity.value = true
  try {
    await agentStore.updateAgent(agentId.value, {
      name: identityForm.value.name,
      description: identityForm.value.description || null,
      system_prompt: identityForm.value.system_prompt || null,
      max_steps: identityForm.value.max_steps,
      allow_continuation: identityForm.value.allow_continuation,
      retry_after_minutes: identityForm.value.retry_after_minutes,
      max_retries: identityForm.value.max_retries,
    })
    identitySaved.value = true
    setTimeout(() => { identitySaved.value = false }, 2000)
  } catch (e) {
    identityError.value = e instanceof ApiError ? e.message : 'Failed to save.'
  } finally {
    savingIdentity.value = false
  }
}


async function saveLlmSettings(): Promise<void> {
  llmSettingsError.value = null
  llmSettingsSaved.value = false
  savingLlmSettings.value = true
  try {
    await agentStore.updateAgent(agentId.value, {
      llm_driver_config_id: llmSettingsForm.value.llm_driver_config_id,
    })
    llmSettingsSaved.value = true
    setTimeout(() => { llmSettingsSaved.value = false }, 2000)
  } catch (e) {
    llmSettingsError.value = e instanceof ApiError ? e.message : 'Failed to save.'
  } finally {
    savingLlmSettings.value = false
  }
}


async function toggleTool(toolName: string): Promise<void> {
  savingTool.value[toolName] = true
  toolsError.value = null
  try {
    if (enabledToolNames.value.has(toolName)) {
      await agentStore.disableTool(agentId.value, toolName)
      enabledToolNames.value.delete(toolName)
    } else {
      // Pre-check: if we already know it can't be enabled, show modal and block
      const status = toolStatusMap.value[toolName]
      if (status && !status.can_enable) {
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      // Attempt enable — backend may still return warning if settings are incomplete
      await agentStore.enableTool(agentId.value, toolName)
      // Re-fetch status to confirm the tool is actually ready before marking enabled
      const newStatus = await toolSettings.getToolStatus(toolName)
      if (newStatus === null) {
        // Couldn't verify status — show warning and do NOT mark as enabled
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      if (!newStatus.can_enable) {
        // Tool is enabled but missing required settings — show warning, keep it disabled
        toolStatusMap.value[toolName] = newStatus
        showEnableWarning(toolName)
        savingTool.value[toolName] = false
        return
      }
      // Tool is fully configured — mark as enabled
      enabledToolNames.value.add(toolName)
      // Update status map
      toolStatusMap.value[toolName] = newStatus
      // Load operation overrides for newly enabled tool
      await loadOperationOverrides()
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update tool.'
  } finally {
    savingTool.value[toolName] = false
  }
}

async function loadOperationOverrides(): Promise<void> {
  // Single batch request for all operations of all enabled tools
  const allStates = await agentStore.getAllOperationOverrides(agentId.value)
  operationStates.value = allStates
}

async function toggleOperationEnabled(toolName: string, operationName: string): Promise<void> {
  savingOperation.value[toolName] = true
  toolsError.value = null
  const prev = operationStates.value[toolName]?.[operationName]
  try {
    const newEnabled = !prev?.enabled

    await agentStore.patchOperationOverride(agentId.value, toolName, operationName, { enabled: newEnabled })

    if (!operationStates.value[toolName]) {
      operationStates.value[toolName] = {}
    }
    operationStates.value[toolName][operationName] = {
      enabled: newEnabled,
      requiresApproval: prev?.requiresApproval ?? true,
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update operation.'
    operationStates.value[toolName]?.[operationName] && (
      operationStates.value[toolName][operationName] = prev
    )
  } finally {
    savingOperation.value[toolName] = false
  }
}

async function toggleOperationAutoApprove(toolName: string, operationName: string): Promise<void> {
  savingOperation.value[toolName] = true
  toolsError.value = null
  const prev = operationStates.value[toolName]?.[operationName]
  try {
    const currentRequiresApproval = prev?.requiresApproval ?? true
    const newRequiresApproval = !currentRequiresApproval

    await agentStore.patchOperationOverride(agentId.value, toolName, operationName, {
      default_requires_approval: newRequiresApproval,
    })

    if (!operationStates.value[toolName]) {
      operationStates.value[toolName] = {}
    }
    operationStates.value[toolName][operationName] = {
      enabled: prev?.enabled ?? true,
      requiresApproval: newRequiresApproval,
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update operation auto-approve.'
    operationStates.value[toolName]?.[operationName] && (
      operationStates.value[toolName][operationName] = prev
    )
  } finally {
    savingOperation.value[toolName] = false
  }
}

// Re-fetch tool status after the config modal saves successfully so the
// "Missing config" badge clears immediately without requiring a page reload.

async function onToolSaved(toolName: string): Promise<void> {
  const newStatus = await toolSettings.getToolStatus(toolName)
  if (newStatus !== null) {
    toolStatusMap.value[toolName] = newStatus
  }
}


async function deleteAgent(): Promise<void> {
  if (confirmDeleteName.value !== agentStore.currentAgent?.name) return
  deleting.value = true
  try {
    await agentStore.deleteAgent(agentId.value)
    router.push({ name: 'dashboard' })
  } catch {
    // handle error
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <AgentLayout :agent-id="agentId">

    <!-- Loading -->
    <div v-if="!agentStore.currentAgent" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <main v-else class="flex-1 py-8 px-6 flex flex-col gap-8">

      <!-- ── Identity ─────────────────────────────────────────────────────── -->
      <section class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
        <h2 class="text-base font-semibold">Identity</h2>
        <div class="flex flex-col gap-1.5">
          <label for="agent-name" class="text-sm font-medium">Name</label>
          <input
            id="agent-name"
            v-model="identityForm.name"
            type="text"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="agent-desc" class="text-sm font-medium">Description <span class="text-muted-foreground font-normal">(optional)</span></label>
          <input
            id="agent-desc"
            v-model="identityForm.description"
            type="text"
            placeholder="What does this agent do?"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="system-prompt" class="text-sm font-medium">System Prompt <span class="text-muted-foreground font-normal">(optional)</span></label>
          <textarea
            id="system-prompt"
            v-model="identityForm.system_prompt"
            rows="4"
            placeholder="Additional instructions for the agent…"
            class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="max-steps" class="text-sm font-medium">Max Steps</label>
          <input
            id="max-steps"
            v-model.number="identityForm.max_steps"
            type="number"
            min="1"
            max="100"
            placeholder="10"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
          <p class="text-xs text-muted-foreground">Maximum number of agent turns (1–100).</p>
        </div>
        <div class="flex items-start gap-3">
          <input
            id="allow-continuation"
            v-model="identityForm.allow_continuation"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
          />
          <div class="flex flex-col gap-1">
            <label for="allow-continuation" class="text-sm font-medium">Allow continuation</label>
            <p class="text-xs text-muted-foreground">When enabled, users can continue a conversation after a task completes.</p>
          </div>
        </div>

        <!-- Auto-Retry -->
        <div class="border-t border-border pt-4 mt-2 flex flex-col gap-4">
          <h3 class="text-sm font-semibold">Auto-Retry</h3>
          <div class="grid grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5">
              <label for="retry-after-minutes" class="text-sm font-medium">Retry after (minutes)</label>
              <input
                id="retry-after-minutes"
                v-model.number="identityForm.retry_after_minutes"
                type="number"
                min="0"
                placeholder="0 = disabled"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <p class="text-xs text-muted-foreground">Wait time before auto-retry (0 = disabled).</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="max-retries" class="text-sm font-medium">Max retries</label>
              <input
                id="max-retries"
                v-model.number="identityForm.max_retries"
                type="number"
                min="0"
                placeholder="0 = no auto-retry"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <p class="text-xs text-muted-foreground">Maximum retry attempts (0 = no auto-retry).</p>
            </div>
          </div>
        </div>
        <div class="flex items-center justify-between">
          <p v-if="identityError" role="alert" class="text-xs text-destructive">{{ identityError }}</p>
          <span v-else-if="identitySaved" class="text-xs text-green-600 dark:text-green-400">Saved!</span>
          <span v-else />
          <button
            @click="saveIdentity"
            :disabled="savingIdentity || !identityForm.name.trim()"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ savingIdentity ? 'Saving…' : 'Save Identity' }}
          </button>
        </div>
      </section>

      <!-- ── LLM Configuration ─────────────────────────────────────────────── -->
      <section class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
        <h2 class="text-base font-semibold">LLM Configuration</h2>
        <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
              <label for="llm-driver-config" class="text-sm font-medium">Configuration</label>
              <button
                @click="showLlmCreate = true"
                class="inline-flex h-7 items-center gap-1 text-xs font-medium text-primary hover:text-primary/80 transition-colors"
              >
                <Icon name="plus" class="h-3.5 w-3.5" />
                Create new
              </button>
            </div>
            <select
              id="llm-driver-config"
              v-model="llmSettingsForm.llm_driver_config_id"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option :value="null">
                {{ preferenceStore.preference ? `— Use my preference: ${preferenceStore.preference.config.name} —` : '— Use global default —' }}
              </option>
              <option v-for="config in llmConfigs" :key="config.id" :value="config.id">
                {{ configLabel(config) }}
              </option>
            </select>
            <p class="text-xs text-muted-foreground mt-1">
              Choose a saved LLM configuration, or leave unset to use your preferred config.
            </p>
          </div>

          <div class="flex items-center justify-between">
            <p v-if="llmSettingsError" role="alert" class="text-xs text-destructive">{{ llmSettingsError }}</p>
            <span v-else-if="llmSettingsSaved" class="text-xs text-green-600 dark:text-green-400">Saved!</span>
            <span v-else />
            <button
              @click="saveLlmSettings"
              :disabled="savingLlmSettings"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ savingLlmSettings ? 'Saving…' : 'Save LLM Configuration' }}
            </button>
          </div>
      </section>

      <!-- ── Tools ───────────────────────────────────────────────────────── -->
      <section class="rounded-xl border border-border bg-card divide-y divide-border">
        <div class="px-5 py-4">
          <h2 class="text-base font-semibold">Tools</h2>
        </div>

        <template v-for="cat in sortedCategories" :key="cat">
          <div class="px-5 py-3 flex items-center justify-between bg-muted/30 cursor-pointer select-none"
               @click="collapsedCategories[cat] = !collapsedCategories[cat]">
            <h3 class="text-sm font-medium">{{ toLabel(cat) }}</h3>
            <div class="flex items-center gap-2">
              <span class="text-xs text-muted-foreground">{{ toolsByCategory[cat].length }}</span>
              <Icon
                   name="chevron-down"
                   :class="`h-4 w-4 text-muted-foreground transition-transform ${collapsedCategories[cat] ? '-rotate-90' : ''}`"
                />
            </div>
          </div>
          <template v-if="!collapsedCategories[cat]">
            <AgentToolListItem
              v-for="tool in toolsByCategory[cat]"
              :key="tool.tool_name"
              :tool="tool"
              :enabled="enabledToolNames.has(tool.tool_name)"
              :saving="savingTool[tool.tool_name] ?? false"
              :missingRequired="toolStatusMap[tool.tool_name]?.missing_required ?? []"
              :operationStates="operationStates[tool.tool_name]"
              @toggle="toggleTool(tool.tool_name)"
              @openConfig="configuringTool = tool.tool_name"
              @toggleOperationEnabled="(op) => toggleOperationEnabled(tool.tool_name, op)"
              @toggleOperationAutoApprove="(op) => toggleOperationAutoApprove(tool.tool_name, op)"
            />
          </template>
        </template>

        <div v-if="loadingTools" class="px-5 py-4 text-sm text-muted-foreground">
          Loading tools…
        </div>
        <div v-else-if="toolRegistry.length === 0" class="px-5 py-4 text-sm text-muted-foreground">
          No tools registered.
        </div>
        <p v-if="toolsError" role="alert" class="px-5 py-3 text-xs text-destructive">{{ toolsError }}</p>
      </section>

      <!-- ── LLM Config Create Modal ─────────────────────────────────────── -->
      <AgentLlmConfigModal
        :show="showLlmCreate"
        :llmDrivers="llmDrivers"
        @update:show="showLlmCreate = $event"
        @created="onLlmCreated"
      />

      <!-- ── Tool Configuration Modal ─────────────────────────────────── -->
      <AgentToolConfigModal
        :toolName="configuringTool"
        :tool="configuringToolSchema()"
        :agentId="agentId"
        @saved="onToolSaved"
        @close="configuringTool = null"
      />

      <!-- ── Pre-activation Warning Modal ────────────────────────────── -->
      <EnableWarningModal
        :toolName="pendingEnableTool"
        :missingRequired="pendingEnableTool ? (toolStatusMap[pendingEnableTool]?.missing_required ?? []) : []"
        @configure="() => { configuringTool = pendingEnableTool; pendingEnableTool = null }"
        @close="pendingEnableTool = null"
      />

      <!-- ── Danger Zone ─────────────────────────────────────────────────── -->
      <section class="rounded-xl border border-destructive/30 bg-card p-5 flex flex-col gap-4">
        <h2 class="text-base font-semibold text-destructive">Danger Zone</h2>
        <div class="flex flex-col gap-1.5">
          <label for="delete-confirm" class="text-sm font-medium">Confirm deletion</label>
          <p class="text-xs text-muted-foreground">Type the agent name <strong>{{ agentStore.currentAgent?.name }}</strong> to confirm.</p>
          <input
            id="delete-confirm"
            v-model="confirmDeleteName"
            type="text"
            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
        <div class="flex justify-end">
          <button
            @click="deleteAgent"
            :disabled="deleting || confirmDeleteName !== agentStore.currentAgent?.name"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-destructive px-4 text-sm font-medium text-destructive-foreground shadow transition-colors hover:bg-destructive/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ deleting ? 'Deleting…' : 'Delete Agent' }}
          </button>
        </div>
      </section>

    </main>
  </AgentLayout>
</template>
