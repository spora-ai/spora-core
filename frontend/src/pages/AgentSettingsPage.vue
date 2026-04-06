<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useThemeStore } from '@/stores/theme'
import type { ToolSchema } from '@/composables/useToolSettings'
import { useToolSettings } from '@/composables/useToolSettings'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import Modal from '@/components/Modal.vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { LLMDriverInfo } from '@/types/llmConfig'
import { ApiError, api } from '@/api/client'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const theme = useThemeStore()

const agentId = computed(() => Number(route.params.id))

// Tool settings composable (per-agent mode)
const { getSettings, putSettings } = useToolSettings(agentId.value)

// ── Tool registry ─────────────────────────────────────────────────────────────

const toolRegistry = ref<ToolSchema[]>([])
const loadingTools = ref(false)

// ── LLM Configs (llm_driver_config_id dropdown) ───────────────────────────────

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
const llmStore = useLlmConfigsStore()
const showLlmCreate = ref(false)
const llmCreateForm = ref({
  name: '',
  driver_class: '',
})
const llmCreateSettings = ref<Record<string, string>>({})
const llmCreateError = ref<string | null>(null)
const savingLlmCreate = ref(false)

const activeCreateDriver = computed<LLMDriverInfo | null>(() =>
  llmDrivers.value.find((d) => d.driver_class === llmCreateForm.value.driver_class) ?? null,
)

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

interface EnabledTool {
  tool_class: string
  tool_name: string
  auto_approve: boolean | null
}

const enabledTools = ref<EnabledTool[]>([])
const savingTools = ref<Record<string, boolean>>({})
const toolsError = ref<string | null>(null)

// Tool configuration panel
const configuringTool = ref<string | null>(null) // tool_name being configured
const serverSettings = ref<Record<string, string>>({})
const savingToolConfig = ref(false)
const toolConfigError = ref<string | null>(null)
const toolConfigSaved = ref(false)
const globalSettingsExist = ref<Record<string, boolean>>({}) // tool_name -> true if global config exists

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
  enabledTools.value = [...agent.tools]

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

// ── LLM Config (llm_driver_config_id) ───────────────────────────────────────

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

// ── LLM Config Creation ─────────────────────────────────────────────────────

function startLlmCreate(): void {
  llmCreateForm.value = { name: '', driver_class: '' }
  llmCreateSettings.value = {}
  llmCreateError.value = null
  showLlmCreate.value = true
}

function onDriverClassChange(): void {
  const driver = llmDrivers.value.find((d) => d.driver_class === llmCreateForm.value.driver_class)
  if (!driver) return
  const defaults: Record<string, string> = {}
  for (const field of driver.settings_schema) {
    if (field.default !== undefined && field.default !== null) {
      defaults[field.key] = String(field.default)
    }
  }
  llmCreateSettings.value = defaults
}

async function submitLlmCreate(): Promise<void> {
  if (!llmCreateForm.value.name.trim() || !llmCreateForm.value.driver_class) return
  savingLlmCreate.value = true
  llmCreateError.value = null
  try {
    const config = await llmStore.createConfig({
      name: llmCreateForm.value.name.trim(),
      driver_class: llmCreateForm.value.driver_class,
      settings: { ...llmCreateSettings.value },
    })
    llmConfigs.value.push(config)
    llmSettingsForm.value.llm_driver_config_id = config.id
    showLlmCreate.value = false
  } catch (e) {
    llmCreateError.value = e instanceof ApiError ? e.message : 'Failed to create configuration.'
  } finally {
    savingLlmCreate.value = false
  }
}

// ── Tools ─────────────────────────────────────────────────────────────────────

function isToolEnabled(toolName: string): boolean {
  return enabledTools.value.some((t) => t.tool_name === toolName)
}

function isToolAutoApproved(toolName: string): boolean {
  const tool = enabledTools.value.find((t) => t.tool_name === toolName)
  return tool?.auto_approve === true
}

async function toggleTool(toolName: string): Promise<void> {
  savingTools.value[toolName] = true
  toolsError.value = null
  try {
    if (isToolEnabled(toolName)) {
      await agentStore.disableTool(agentId.value, toolName)
      enabledTools.value = enabledTools.value.filter((t) => t.tool_name !== toolName)
    } else {
      const tool = await agentStore.enableTool(agentId.value, toolName)
      enabledTools.value.push(tool)
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update tool.'
  } finally {
    savingTools.value[toolName] = false
  }
}

async function toggleAutoApprove(toolName: string): Promise<void> {
  const tool = enabledTools.value.find((t) => t.tool_name === toolName)
  if (!tool) return
  savingTools.value[toolName] = true
  try {
    const newValue = tool.auto_approve !== true
    await agentStore.patchTool(agentId.value, toolName, { auto_approve: newValue })
    tool.auto_approve = newValue
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update auto-approve.'
  } finally {
    savingTools.value[toolName] = false
  }
}

// ── Tool configuration ──────────────────────────────────────────────────────────

function toolSchema(toolName: string): ToolSchema | undefined {
  return toolRegistry.value.find((t) => t.tool_name === toolName)
}

async function openToolConfig(toolName: string): Promise<void> {
  configuringTool.value = toolName
  toolConfigError.value = null
  toolConfigSaved.value = false

  // Check if global config exists
  try {
    await api.get(`/tools/${encodeURIComponent(toolName)}/settings`)
    globalSettingsExist.value[toolName] = true
  } catch {
    globalSettingsExist.value[toolName] = false
  }

  // Load effective (merged) settings
  try {
    serverSettings.value = await getSettings(toolName)
  } catch {
    serverSettings.value = {}
  }
}

function closeToolConfig(): void {
  configuringTool.value = null
  serverSettings.value = {}
  toolConfigError.value = null
  toolConfigSaved.value = false
}

async function saveToolConfig(settings: Record<string, string>): Promise<void> {
  if (!configuringTool.value) return
  savingToolConfig.value = true
  toolConfigError.value = null
  try {
    serverSettings.value = await putSettings(configuringTool.value, settings, serverSettings.value)
    toolConfigSaved.value = true
    setTimeout(() => { toolConfigSaved.value = false }, 2000)
  } catch (e) {
    toolConfigError.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    savingToolConfig.value = false
  }
}

function goToGlobalSettings(): void {
  router.push({ name: 'settings' })
}

// ── Delete ────────────────────────────────────────────────────────────────────

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
                @click="startLlmCreate"
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

      <!-- ── LLM Config Create Modal ─────────────────────────────────────── -->
      <Modal
        v-model="showLlmCreate"
        title="New LLM Configuration"
        size="md"
        @close="showLlmCreate = false"
      >
        <div class="flex flex-col gap-4">
          <p v-if="llmCreateError" role="alert" class="text-xs text-destructive">{{ llmCreateError }}</p>

          <!-- Name -->
          <div class="flex flex-col gap-1.5">
            <label for="llm-create-name" class="text-sm font-medium">Name</label>
            <input
              id="llm-create-name"
              v-model="llmCreateForm.name"
              type="text"
              placeholder="My OpenAI Config"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
            />
          </div>

          <!-- Driver -->
          <div class="flex flex-col gap-1.5">
            <label for="llm-create-driver" class="text-sm font-medium">Driver</label>
            <select
              id="llm-create-driver"
              v-model="llmCreateForm.driver_class"
              @change="onDriverClassChange"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="">— Select a driver —</option>
              <option v-for="driver in llmDrivers" :key="driver.driver_class" :value="driver.driver_class">
                {{ driver.display_name }} ({{ driver.name }})
              </option>
            </select>
          </div>

          <!-- Settings -->
          <div v-if="activeCreateDriver">
            <h3 class="text-sm font-semibold mb-3">Settings</h3>
            <ToolSettingsForm
              :tool="{ tool_class: activeCreateDriver.driver_class, tool_name: activeCreateDriver.name, display_name: activeCreateDriver.display_name, settings_schema: activeCreateDriver.settings_schema }"
              :initialSettings="llmCreateSettings"
              :saving="savingLlmCreate"
              :error="null"
              @save="(s) => { llmCreateSettings = s }"
            />
          </div>
        </div>

        <template #footer>
          <div class="flex justify-end gap-2">
            <button
              @click="showLlmCreate = false"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
            >
              Cancel
            </button>
            <button
              @click="submitLlmCreate"
              :disabled="savingLlmCreate || !llmCreateForm.name.trim() || !llmCreateForm.driver_class"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ savingLlmCreate ? 'Creating…' : 'Create' }}
            </button>
          </div>
        </template>
      </Modal>

      <!-- ── Tools ───────────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">Tools</h2>
        <div class="rounded-xl border border-border bg-card divide-y divide-border">
          <div
            v-for="tool in toolRegistry"
            :key="tool.tool_class"
            class="px-5 py-4 flex items-start gap-4"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
              <p v-if="tool.settings_schema.length > 0" class="text-xs mt-0.5" :class="isToolEnabled(tool.tool_name) ? 'text-muted-foreground' : 'text-muted-foreground/50'">
                {{ isToolEnabled(tool.tool_name) ? 'Has credentials to configure' : 'Enable to configure credentials' }}
              </p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <!-- Configure button (only for enabled tools with settings_schema) -->
              <button
                v-if="isToolEnabled(tool.tool_name) && tool.settings_schema.length > 0"
                @click="openToolConfig(tool.tool_name)"
                class="inline-flex h-7 items-center justify-center rounded-lg border border-border bg-background px-3 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
              >
                Configure
              </button>
              <!-- Auto-approve toggle (only if enabled) -->
              <label
                v-if="isToolEnabled(tool.tool_name)"
                class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer"
                :title="isToolAutoApproved(tool.tool_name) ? 'Auto-approve is on' : 'Auto-approve is off'"
              >
                <span>Auto-approve</span>
                <button
                  @click="toggleAutoApprove(tool.tool_name)"
                  :disabled="savingTools[tool.tool_name]"
                  class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
                  :class="isToolAutoApproved(tool.tool_name) ? 'bg-primary' : 'bg-muted'"
                >
                  <span
                    class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform"
                    :class="isToolAutoApproved(tool.tool_name) ? 'translate-x-4' : 'translate-x-0.5'"
                  />
                </button>
              </label>
              <!-- Enable/Disable toggle -->
              <button
                @click="toggleTool(tool.tool_name)"
                :disabled="savingTools[tool.tool_name]"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
                :class="isToolEnabled(tool.tool_name) ? 'bg-primary' : 'bg-muted'"
              >
                <span
                  class="inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
                  :class="isToolEnabled(tool.tool_name) ? 'translate-x-6' : 'translate-x-1'"
                />
              </button>
            </div>
          </div>

          <div v-if="loadingTools" class="px-5 py-4 text-sm text-muted-foreground">
            Loading tools…
          </div>
          <div v-else-if="toolRegistry.length === 0" class="px-5 py-4 text-sm text-muted-foreground">
            No tools registered.
          </div>
        </div>
        <p v-if="toolsError" role="alert" class="text-xs text-destructive">{{ toolsError }}</p>
      </section>

      <!-- ── Tool Configuration Modal ─────────────────────────────────── -->
      <Modal
        :modelValue="configuringTool !== null"
        :title="(toolSchema(configuringTool ?? '')?.display_name || (configuringTool ?? ''))"
        size="md"
        @update:modelValue="(v) => !v && closeToolConfig()"
        @close="closeToolConfig"
      >
        <!-- No global config warning -->
        <div
          v-if="configuringTool && !globalSettingsExist[configuringTool]"
          class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 px-4 py-3 text-sm text-amber-700 dark:text-amber-300 mb-4"
        >
          <p class="font-medium mb-1">No global configuration found</p>
          <p class="text-xs opacity-80 mb-2">This tool has no global credentials set. You can configure it locally for this agent, or set up global defaults first.</p>
          <button
            @click="goToGlobalSettings"
            class="inline-flex h-7 items-center justify-center rounded-lg bg-amber-200 dark:bg-amber-800 px-3 text-xs font-medium text-amber-800 dark:text-amber-200 hover:bg-amber-300 dark:hover:bg-amber-700 transition-colors"
          >
            Go to Global Settings →
          </button>
        </div>

        <!-- Config form -->
        <ToolSettingsForm
          v-if="configuringTool && toolSchema(configuringTool)"
          :tool="toolSchema(configuringTool)!"
          :initialSettings="serverSettings"
          :saving="savingToolConfig"
          :error="toolConfigError"
          @save="saveToolConfig"
        />
      </Modal>

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