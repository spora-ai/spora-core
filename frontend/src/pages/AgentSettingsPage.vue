<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '@/stores/agent'
import { useThemeStore } from '@/stores/theme'
import { api } from '@/api/client'
import { ApiError } from '@/api/client'

const route = useRoute()
const router = useRouter()
const agentStore = useAgentStore()
const theme = useThemeStore()

const agentId = computed(() => Number(route.params.id))

// ── Tool registry ─────────────────────────────────────────────────────────────

interface ToolSchema {
  tool_class: string
  tool_name: string
  settings_schema: {
    key: string
    label: string
    type: string
    description: string
    default: unknown
    required: boolean
    scope: string
    options: string[] | null
  }[]
}

const toolRegistry = ref<ToolSchema[]>([])
const loadingTools = ref(false)

// ── LLM Config (from LLMConfiguration override) ────────────────────────────────

const llmConfig = ref<Record<string, string>>({})
const savingLlmConfig = ref(false)
const llmConfigError = ref<string | null>(null)
const llmConfigSaved = ref(false)

// ── Identity form ─────────────────────────────────────────────────────────────

const identityForm = ref({
  name: '',
  description: '',
  system_prompt: '',
})
const savingIdentity = ref(false)
const identityError = ref<string | null>(null)
const identitySaved = ref(false)

// ── LLM settings form (provider, model, base_url on agent) ────────────────────

const llmSettingsForm = ref({
  llm_provider: 'openai_compatible',
  llm_model: 'gpt-4o',
  llm_base_url: '',
})
const savingLlmSettings = ref(false)
const llmSettingsError = ref<string | null>(null)
const llmSettingsSaved = ref(false)

// ── Tools ─────────────────────────────────────────────────────────────────────

interface EnabledTool {
  tool_class: string
  tool_name: string
  auto_approve: boolean | null
}

const enabledTools = ref<EnabledTool[]>([])
const savingTools = ref<Record<string, boolean>>({})
const toolsError = ref<string | null>(null)

// ── Delete agent ──────────────────────────────────────────────────────────────

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
    llm_provider: agent.llm_provider,
    llm_model: agent.llm_model,
    llm_base_url: agent.llm_base_url ?? '',
  }
  enabledTools.value = [...agent.tools]

  // Fetch LLM config override
  try {
    llmConfig.value = (await agentStore.getLLMConfig(agentId.value)) as Record<string, string>
  } catch {
    llmConfig.value = {}
  }

  // Fetch tool registry
  try {
    loadingTools.value = true
    const result = await api.get<{ tools: ToolSchema[] }>('/tools')
    toolRegistry.value = result.tools
  } catch {
    toolRegistry.value = []
  } finally {
    loadingTools.value = false
  }
})

// ── Identity ──────────────────────────────────────────────────────────────────

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

// ── LLM Settings (provider, model, base_url) ─────────────────────────────────

async function saveLlmSettings(): Promise<void> {
  llmSettingsError.value = null
  llmSettingsSaved.value = false
  savingLlmSettings.value = true
  try {
    await agentStore.updateAgent(agentId.value, {
      llm_provider: llmSettingsForm.value.llm_provider,
      llm_model: llmSettingsForm.value.llm_model,
      llm_base_url: llmSettingsForm.value.llm_base_url || null,
    })
    llmSettingsSaved.value = true
    setTimeout(() => { llmSettingsSaved.value = false }, 2000)
  } catch (e) {
    llmSettingsError.value = e instanceof ApiError ? e.message : 'Failed to save.'
  } finally {
    savingLlmSettings.value = false
  }
}

// ── LLM API Keys ───────────────────────────────────────────────────────────────

async function saveLlmConfig(): Promise<void> {
  llmConfigError.value = null
  llmConfigSaved.value = false
  savingLlmConfig.value = true
  try {
    llmConfig.value = await agentStore.putLLMConfig(agentId.value, llmConfig.value as Record<string, string>) as Record<string, string>
    llmConfigSaved.value = true
    setTimeout(() => { llmConfigSaved.value = false }, 2000)
  } catch (e) {
    llmConfigError.value = e instanceof ApiError ? e.message : 'Failed to save API keys.'
  } finally {
    savingLlmConfig.value = false
  }
}

// ── Tools ─────────────────────────────────────────────────────────────────────

function isToolEnabled(toolClass: string): boolean {
  return enabledTools.value.some((t) => t.tool_class === toolClass)
}

function isToolAutoApproved(toolClass: string): boolean {
  const tool = enabledTools.value.find((t) => t.tool_class === toolClass)
  return tool?.auto_approve === true
}

async function toggleTool(toolClass: string): Promise<void> {
  savingTools.value[toolClass] = true
  toolsError.value = null
  try {
    if (isToolEnabled(toolClass)) {
      await agentStore.disableTool(agentId.value, toolClass)
      enabledTools.value = enabledTools.value.filter((t) => t.tool_class !== toolClass)
    } else {
      const tool = await agentStore.enableTool(agentId.value, toolClass)
      enabledTools.value.push(tool)
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update tool.'
  } finally {
    savingTools.value[toolClass] = false
  }
}

async function toggleAutoApprove(toolClass: string): Promise<void> {
  const tool = enabledTools.value.find((t) => t.tool_class === toolClass)
  if (!tool) return
  savingTools.value[toolClass] = true
  try {
    const newValue = tool.auto_approve !== true
    await agentStore.patchTool(agentId.value, toolClass, { auto_approve: newValue })
    tool.auto_approve = newValue
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to update auto-approve.'
  } finally {
    savingTools.value[toolClass] = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────

async function deleteAgent(): Promise<void> {
  if (confirmDeleteName.value !== agentStore.currentAgent?.name) return
  deleting.value = true
  try {
    await agentStore.deleteAgent(agentId.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
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

      <!-- ── LLM Settings ─────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">LLM Settings</h2>
        <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">

          <!-- Provider -->
          <div class="flex flex-col gap-1.5">
            <label for="llm-provider" class="text-sm font-medium">Provider</label>
            <select
              id="llm-provider"
              v-model="llmSettingsForm.llm_provider"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="openai_compatible">OpenAI Compatible (GPT, Claude via API compatible endpoint)</option>
              <option value="anthropic">Anthropic (Claude)</option>
            </select>
          </div>

          <!-- Model -->
          <div class="flex flex-col gap-1.5">
            <label for="llm-model" class="text-sm font-medium">Model</label>
            <input
              id="llm-model"
              v-model="llmSettingsForm.llm_model"
              type="text"
              placeholder="e.g. gpt-4o, claude-3-5-sonnet"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          <!-- Base URL (for openai_compatible) -->
          <div v-if="llmSettingsForm.llm_provider === 'openai_compatible'" class="flex flex-col gap-1.5">
            <label for="llm-base-url" class="text-sm font-medium">Base URL <span class="text-muted-foreground font-normal">(optional)</span></label>
            <input
              id="llm-base-url"
              v-model="llmSettingsForm.llm_base_url"
              type="url"
              placeholder="https://api.openai.com/v1"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <p class="text-xs text-muted-foreground">Used for OpenAI-compatible endpoints (Ollama, Groq, LM Studio, Azure, etc.)</p>
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
              {{ savingLlmSettings ? 'Saving…' : 'Save LLM Settings' }}
            </button>
          </div>
        </div>
      </section>

      <!-- ── API Keys ──────────────────────────────────────────────────────── -->
      <section class="flex flex-col gap-4">
        <h2 class="text-base font-semibold">API Keys</h2>
        <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <label for="openai-key" class="text-sm font-medium">OpenAI API Key</label>
            <input
              id="openai-key"
              v-model="llmConfig['core.openai.api_key']"
              type="password"
              placeholder="sk-…"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <p class="text-xs text-muted-foreground">Used when provider is OpenAI Compatible.</p>
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="anthropic-key" class="text-sm font-medium">Anthropic API Key</label>
            <input
              id="anthropic-key"
              v-model="llmConfig['core.anthropic.api_key']"
              type="password"
              placeholder="sk-ant-…"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <p class="text-xs text-muted-foreground">Used when provider is Anthropic.</p>
          </div>
          <div class="flex items-center justify-between">
            <p v-if="llmConfigError" role="alert" class="text-xs text-destructive">{{ llmConfigError }}</p>
            <span v-else-if="llmConfigSaved" class="text-xs text-green-600 dark:text-green-400">Saved!</span>
            <span v-else />
            <button
              @click="saveLlmConfig"
              :disabled="savingLlmConfig"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ savingLlmConfig ? 'Saving…' : 'Save API Keys' }}
            </button>
          </div>
        </div>
      </section>

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
              <p class="text-sm font-medium">{{ tool.tool_name }}</p>
              <p v-if="tool.settings_schema.length > 0" class="text-xs text-muted-foreground mt-0.5">
                Has credentials to configure
              </p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <!-- Auto-approve toggle (only if enabled) -->
              <label
                v-if="isToolEnabled(tool.tool_class)"
                class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer"
                :title="isToolAutoApproved(tool.tool_class) ? 'Auto-approve is on' : 'Auto-approve is off'"
              >
                <span>Auto-approve</span>
                <button
                  @click="toggleAutoApprove(tool.tool_class)"
                  :disabled="savingTools[tool.tool_class]"
                  class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
                  :class="isToolAutoApproved(tool.tool_class) ? 'bg-primary' : 'bg-muted'"
                >
                  <span
                    class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform"
                    :class="isToolAutoApproved(tool.tool_class) ? 'translate-x-4' : 'translate-x-0.5'"
                  />
                </button>
              </label>
              <!-- Enable/Disable toggle -->
              <button
                @click="toggleTool(tool.tool_class)"
                :disabled="savingTools[tool.tool_class]"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
                :class="isToolEnabled(tool.tool_class) ? 'bg-primary' : 'bg-muted'"
              >
                <span
                  class="inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform"
                  :class="isToolEnabled(tool.tool_class) ? 'translate-x-6' : 'translate-x-1'"
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

      <!-- ── Danger Zone ──────────────────────────────────────────────────── -->
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
