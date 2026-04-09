<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useThemeStore } from '@/stores/theme'
import type { ToolSchema } from '@/composables/useToolSettings'
import AgentLlmConfigModal from '@/components/agent/AgentLlmConfigModal.vue'
import AgentToolConfigModal from '@/components/agent/AgentToolConfigModal.vue'
import AgentToolListItem from '@/components/agent/AgentToolListItem.vue'
import type { LLMDriverInfo } from '@/types/llmConfig'
import { ApiError, api } from '@/api/client'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const theme = useThemeStore()

const agentId = computed(() => Number(route.params.id))

// ── Tool registry ─────────────────────────────────────────────────────────────

const toolRegistry = ref<ToolSchema[]>([])
const loadingTools = ref(false)

// ── LLM Configs ───────────────────────────────────────────────────────────────

interface LLMConfigResource {
  id: number
  name: string
  driver_display_name: string
  driver_class: string
  is_default: boolean
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

// ── Identity form ─────────────────────────────────────────────────────────────

const identityForm = ref({
  name: '',
  description: '',
  system_prompt: '',
})
const savingIdentity = ref(false)
const identityError = ref<string | null>(null)
const identitySaved = ref(false)

// ── Tools ─────────────────────────────────────────────────────────────────────

// Track enabled tool names and auto-approve status separately
const enabledToolNames = ref<Set<string>>(new Set())
const autoApprovedMap = ref<Record<string, boolean>>({})

const savingTools = ref<Record<string, boolean>>({})
const toolsError = ref<string | null>(null)

// Tool configuration modal
const configuringTool = ref<string | null>(null)

function configuringToolSchema(): ToolSchema | null {
  return toolRegistry.value.find((t) => t.tool_name === configuringTool.value) ?? null
}

// ── Delete agent ─────────────────────────────────────────────────────────────

const deleting = ref(false)
const confirmDeleteName = ref('')

// ── Load data ────────────────────────────────────────────────────────────────

onMounted(async () => {
  await agentStore.fetchAgent(agentId.value)

  const agent = agentStore.currentAgent!
  identityForm.value = {
    name: agent.name,
    description: agent.description ?? '',
    system_prompt: agent.system_prompt ?? '',
  }
  llmSettingsForm.value = {
    llm_driver_config_id: agent.llm_driver_config_id ?? null,
  }

  // Seed enabled tools state
  enabledToolNames.value = new Set(agent.tools.map((t) => t.tool_name))
  for (const t of agent.tools) {
    autoApprovedMap.value[t.tool_name] = t.auto_approve === true
  }

  // Fetch tool registry, LLM configs, and LLM drivers in parallel
  const [toolsResult, configsResult, driversResult] = await Promise.all([
    api.get<{ tools: ToolSchema[] }>('/tools'),
    api.get<{ configs: LLMConfigResource[] }>('/llm-configs'),
    api.get<{ drivers: LLMDriverInfo[] }>('/llm-drivers'),
  ])
  toolRegistry.value = toolsResult.tools
  llmConfigs.value = configsResult.configs
  llmDrivers.value = driversResult.drivers
})

// ── Identity ─────────────────────────────────────────────────────────────────

async function saveIdentity(): Promise<void> {
  identityError.value = null
  identitySaved.value = false
  savingIdentity.value = true
  try {
    await agentStore.updateAgent(agentId.value, {
      name: identityForm.value.name,
      description: identityForm.value.description || null,
      system_prompt: identityForm.value.system_prompt || null,
    })
    identitySaved.value = true
    setTimeout(() => { identitySaved.value = false }, 2000)
  } catch (e) {
    identityError.value = e instanceof ApiError ? e.message : 'Failed to save.'
  } finally {
    savingIdentity.value = false
  }
}

// ── LLM Config ───────────────────────────────────────────────────────────────

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

// ── Tools ─────────────────────────────────────────────────────────────────────

async function toggleTool(toolName: string): Promise<void> {
  savingTools.value[toolName] = true
  toolsError.value = null
  try {
    if (enabledToolNames.value.has(toolName)) {
      await agentStore.disableTool(agentId.value, toolName)
      enabledToolNames.value.delete(toolName)
      delete autoApprovedMap.value[toolName]
    } else {
      const tool = await agentStore.enableTool(agentId.value, toolName)
      enabledToolNames.value.add(toolName)
      autoApprovedMap.value[toolName] = tool.auto_approve === true
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update tool.'
  } finally {
    savingTools.value[toolName] = false
  }
}

async function toggleAutoApprove(toolName: string): Promise<void> {
  if (!enabledToolNames.value.has(toolName)) return
  savingTools.value[toolName] = true
  try {
    const newValue = !autoApprovedMap.value[toolName]
    await agentStore.patchTool(agentId.value, toolName, { auto_approve: newValue })
    autoApprovedMap.value[toolName] = newValue
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update auto-approve.'
  } finally {
    savingTools.value[toolName] = false
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────

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
  <div class="min-h-screen bg-background flex flex-col">

    <!-- Header -->
    <header class="border-b border-border px-4 py-3 flex items-center gap-3 shrink-0">
      <button
        @click="router.push({ name: 'agent', params: { id: agentId } })"
        class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        Back
      </button>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium truncate">Settings</p>
      </div>
      <!-- Dark mode toggle -->
      <button
        @click="theme.toggle()"
        class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      >
        <svg v-if="theme.isDark" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
      </button>
    </header>

    <!-- Loading -->
    <div v-if="!agentStore.currentAgent" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      Loading…
    </div>

    <main v-else class="flex-1 max-w-2xl w-full mx-auto px-4 py-8 flex flex-col gap-8">

      <!-- ── Identity ─────────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">Identity</h2>
        <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
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
        </div>
      </section>

      <!-- ── LLM Configuration ─────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">LLM Configuration</h2>
        <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
              <label for="llm-driver-config" class="text-sm font-medium">Configuration</label>
              <button
                @click="showLlmCreate = true"
                class="inline-flex h-7 items-center gap-1 text-xs font-medium text-primary hover:text-primary/80 transition-colors"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Create new
              </button>
            </div>
            <select
              id="llm-driver-config"
              v-model="llmSettingsForm.llm_driver_config_id"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option :value="null">— Use global default —</option>
              <option v-for="config in llmConfigs" :key="config.id" :value="config.id">
                {{ config.name }} ({{ config.driver_display_name }})
              </option>
            </select>
            <p class="text-xs text-muted-foreground mt-1">
              Choose a saved LLM configuration, or leave unset to use your global default.
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
        </div>
      </section>

      <!-- ── Tools ───────────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">Tools</h2>
        <div class="rounded-xl border border-border bg-card divide-y divide-border">
          <AgentToolListItem
            v-for="tool in toolRegistry"
            :key="tool.tool_class"
            :tool="tool"
            :enabled="enabledToolNames.has(tool.tool_name)"
            :autoApproved="autoApprovedMap[tool.tool_name] ?? false"
            :saving="savingTools[tool.tool_name] ?? false"
            @toggle="toggleTool(tool.tool_name)"
            @toggleAutoApprove="toggleAutoApprove(tool.tool_name)"
            @openConfig="configuringTool = tool.tool_name"
          />

          <div v-if="loadingTools" class="px-5 py-4 text-sm text-muted-foreground">
            Loading tools…
          </div>
          <div v-else-if="toolRegistry.length === 0" class="px-5 py-4 text-sm text-muted-foreground">
            No tools registered.
          </div>
        </div>
        <p v-if="toolsError" role="alert" class="text-xs text-destructive">{{ toolsError }}</p>
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
        @close="configuringTool = null"
      />

      <!-- ── Danger Zone ─────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold text-destructive">Danger Zone</h2>
        <div class="rounded-xl border border-destructive/30 bg-card p-5 flex flex-col gap-4">
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
        </div>
      </section>

    </main>
  </div>
</template>
