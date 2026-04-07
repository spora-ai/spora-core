<script setup lang="ts">
/**
 * GlobalSettingsPage — /settings
 *
 * Unified settings page with two sections:
 * - Tools: global tool configuration (existing content)
 * - LLM Drivers: LLM configuration management (migrated from LLMConfigsPage)
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { ApiError } from '@/api/client'
import { useToolSettings } from '@/composables/useToolSettings'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import SettingsSidebar from '@/components/settings/SettingsSidebar.vue'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { ToolSchema } from '@/composables/useToolSettings'
import type { LLMDriverInfo, LLMConfigResource } from '@/types/llmConfig'

// ── Section routing ───────────────────────────────────────────────────────────

const route = useRoute()
const router = useRouter()

const selectedSection = ref<'overview' | 'tools' | 'llm'>(
  (route.query.section as string) === 'llm' ? 'llm' : (route.query.section as string) === 'tools' ? 'tools' : 'overview',
)

// ── Shared state (tool settings composable) ────────────────────────────────────

const { getSettings, putSettings } = useToolSettings()

// ── Tools section ─────────────────────────────────────────────────────────────

const allTools = ref<ToolSchema[]>([])
const loadingTools = ref(false)
const toolsError = ref<string | null>(null)

const selectedToolId = ref<string | null>(null)
const selectedTool = computed<ToolSchema | null>(
  () => allTools.value.find((t) => t.tool_name === selectedToolId.value) ?? null,
)

const serverSettings = ref<Record<string, string>>({})
const saving = ref(false)
const saveError = ref<string | null>(null)
const savedFlash = ref(false)

function toolsWithSettings(): ToolSchema[] {
  return allTools.value.filter((t) => t.settings_schema.length > 0)
}

async function selectTool(toolId: string): Promise<void> {
  selectedToolId.value = toolId
  saveError.value = null
  savedFlash.value = false
  try {
    serverSettings.value = await getSettings(toolId)
  } catch {
    serverSettings.value = {}
  }
}

async function onSave(settings: Record<string, string>): Promise<void> {
  if (!selectedToolId.value) return
  saving.value = true
  saveError.value = null
  try {
    serverSettings.value = await putSettings(selectedToolId.value, settings, serverSettings.value)
    savedFlash.value = true
    setTimeout(() => { savedFlash.value = false }, 2000)
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}

// ── LLM section (migrated from LLMConfigsPage) ─────────────────────────────────

const llmStore = useLlmConfigsStore()

// View mode: 'list' | 'create' | 'edit'
const llmViewMode = ref<'list' | 'create' | 'edit'>('list')
const selectedConfigId = ref<number | null>(null)
const selectedConfig = computed<LLMConfigResource | null>(
  () => llmStore.configs.find((c) => c.id === selectedConfigId.value) ?? null,
)
const selectedDriver = computed<LLMDriverInfo | null>(() => {
  if (!selectedConfig.value) return null
  return llmStore.driverForClass(selectedConfig.value.driver_class) ?? null
})

const formName = ref('')
const formDriverClass = ref('')
const formSettings = ref<Record<string, string>>({})
const serverSettingsLLM = ref<Record<string, string>>({})
const saveLLMError = ref<string | null>(null)
const savedLLMFlash = ref(false)
const formSetAsDefault = ref(false)

const activeDriver = computed<LLMDriverInfo | null>(() => {
  if (llmViewMode.value === 'create') {
    return llmStore.driverByName(formDriverClass.value) ?? null
  }
  return selectedDriver.value
})

// ── LLM section load ───────────────────────────────────────────────────────────

onMounted(async () => {
  // Always load tools
  loadingTools.value = true
  toolsError.value = null
  try {
    const result = await api.get<{ tools: ToolSchema[] }>('/tools')
    allTools.value = result.tools
    const firstWithSettings = result.tools.find((t) => t.settings_schema.length > 0)
    if (firstWithSettings) {
      await selectTool(firstWithSettings.tool_name)
    }
  } catch (e) {
    toolsError.value = e instanceof ApiError ? e.message : 'Failed to load tools.'
  } finally {
    loadingTools.value = false
  }

  // Load LLM configs if on that section
  if (selectedSection.value === 'llm' || selectedSection.value === 'overview') {
    await Promise.all([llmStore.loadDrivers(), llmStore.loadConfigs()])
  }
})

// Watch section changes to load LLM data
async function selectSection(section: 'overview' | 'tools' | 'llm'): Promise<void> {
  selectedSection.value = section
  router.replace({ query: section === 'llm' ? { section: 'llm' } : section === 'tools' ? { section: 'tools' } : {} })
  if (section === 'tools') {
    selectedToolId.value = null
  }
  if (section === 'llm' && llmStore.drivers.length === 0) {
    await Promise.all([llmStore.loadDrivers(), llmStore.loadConfigs()])
  }
  if (section === 'overview' && llmStore.drivers.length === 0) {
    await Promise.all([llmStore.loadDrivers(), llmStore.loadConfigs()])
  }
}

// ── LLM section actions ───────────────────────────────────────────────────────

function selectConfig(config: LLMConfigResource): void {
  selectedConfigId.value = config.id
  llmViewMode.value = 'edit'
  saveLLMError.value = null
  serverSettingsLLM.value = { ...config.settings }
  formSettings.value = { ...config.settings }
}

function startCreate(): void {
  llmViewMode.value = 'create'
  selectedConfigId.value = null
  formName.value = ''
  formDriverClass.value = ''
  formSettings.value = {}
  serverSettingsLLM.value = {}
  saveLLMError.value = null
  formSetAsDefault.value = llmStore.configs.length === 0
}

function cancelLLMForm(): void {
  llmViewMode.value = llmStore.configs.length > 0 ? 'edit' : 'list'
  selectedConfigId.value = null
  saveLLMError.value = null
}

async function submitCreate(): Promise<void> {
  if (!formDriverClass.value || !formName.value.trim()) return
  const driver = llmStore.driverByName(formDriverClass.value)
  if (!driver) return

  saveLLMError.value = null
  savingLLMStart()

  try {
    const config = await llmStore.createConfig({
      name: formName.value.trim(),
      driver_class: driver.driver_class,
      settings: { ...formSettings.value },
      is_default: formSetAsDefault.value,
    })
    savedLLMFlash.value = true
    setTimeout(() => { savedLLMFlash.value = false }, 2000)
    llmViewMode.value = 'edit'
    selectedConfigId.value = config.id
    serverSettingsLLM.value = { ...config.settings }
    formSettings.value = { ...config.settings }
  } catch (e) {
    saveLLMError.value = e instanceof ApiError ? e.message : 'Failed to create configuration.'
  } finally {
    savingLLMEnd()
  }
}

async function submitEdit(): Promise<void> {
  if (!selectedConfig.value) return

  saveLLMError.value = null
  savingLLMStart()

  try {
    const updated = await llmStore.updateConfig(selectedConfig.value.id, {
      settings: { ...formSettings.value },
    })
    savedLLMFlash.value = true
    setTimeout(() => { savedLLMFlash.value = false }, 2000)
    serverSettingsLLM.value = { ...updated.settings }
    formSettings.value = { ...updated.settings }
  } catch (e) {
    saveLLMError.value = e instanceof ApiError ? e.message : 'Failed to update configuration.'
  } finally {
    savingLLMEnd()
  }
}

async function submitSetDefault(): Promise<void> {
  if (!selectedConfig.value) return
  try {
    await llmStore.setDefault(selectedConfig.value.id)
  } catch (e) {
    saveLLMError.value = e instanceof ApiError ? e.message : 'Failed to set as default.'
  }
}

async function submitDelete(): Promise<void> {
  if (!selectedConfig.value) return
  if (!window.confirm(`Delete configuration "${selectedConfig.value.name}"? This cannot be undone.`)) return
  try {
    await llmStore.deleteConfig(selectedConfig.value.id)
    llmViewMode.value = 'list'
    selectedConfigId.value = null
  } catch (e) {
    saveLLMError.value = e instanceof ApiError ? e.message : 'Failed to delete configuration.'
  }
}

function onDriverChange(): void {
  const driver = llmStore.driverByName(formDriverClass.value)
  if (!driver) return
  const defaults: Record<string, string> = {}
  for (const field of driver.settings_schema) {
    if (field.default !== undefined && field.default !== null) {
      defaults[field.key] = String(field.default)
    }
  }
  formSettings.value = defaults
}

// ── LLM helpers ────────────────────────────────────────────────────────────────

const savingLLM = ref(false)
function savingLLMStart(): void { savingLLM.value = true }
function savingLLMEnd(): void { savingLLM.value = false }

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">

    <GlobalNavbar />

    <div class="flex-1 flex">

      <!-- Left sidebar: settings navigation -->
      <SettingsSidebar
        :selectedSection="selectedSection"
        :allTools="allTools"
        :loadingTools="loadingTools"
        :selectedToolId="selectedToolId"
        :selectedConfigId="selectedConfigId"
        @update:selectedSection="(s: 'overview' | 'tools' | 'llm') => selectSection(s)"
        @selectTool="selectTool"
        @selectConfig="selectConfig"
        @startCreate="startCreate"
      />

      <!-- Main content -->
      <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8">

        <!-- ── Overview section ─────────────────────────────────────────── -->
        <template v-if="selectedSection === 'overview'">

          <!-- Mobile section selector -->
          <div class="md:hidden mb-6 flex gap-2">
            <button
              @click="selectSection('tools')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              Tools →
            </button>
            <button
              @click="selectSection('llm')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              LLM →
            </button>
          </div>

          <div class="mb-6">
            <h1 class="text-lg font-semibold">Global Settings</h1>
            <p class="text-sm text-muted-foreground mt-0.5">
              Manage your tools and LLM provider configurations.
            </p>
          </div>

          <!-- Tools overview -->
          <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
              <h2 class="text-sm font-semibold">Tools</h2>
              <button
                @click="selectSection('tools')"
                class="text-xs text-primary hover:text-primary/80 font-medium"
              >
                View all →
              </button>
            </div>
            <div v-if="loadingTools" class="text-sm text-muted-foreground">Loading…</div>
            <div v-else-if="toolsWithSettings().length === 0" class="rounded-xl border border-border bg-card p-5 text-sm text-muted-foreground">
              No configurable tools available.
            </div>
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <button
                v-for="tool in toolsWithSettings().slice(0, 6)"
                :key="tool.tool_name"
                @click="selectedSection = 'tools'; selectTool(tool.tool_name)"
                class="rounded-xl border border-border bg-card p-4 text-left hover:border-primary/50 hover:bg-muted/50 transition-colors"
              >
                <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
                <p class="text-xs text-muted-foreground mt-0.5">{{ tool.settings_schema.length }} settings</p>
              </button>
            </div>
          </div>

        </template>

        <!-- ── Tools section ─────────────────────────────────────────────── -->
        <template v-if="selectedSection === 'tools'">

          <!-- Mobile section selector -->
          <div class="md:hidden mb-6 flex gap-2">
            <button
              @click="selectSection('overview')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              ← Overview
            </button>
            <button
              @click="selectSection('llm')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              LLM →
            </button>
          </div>

          <!-- Mobile tool selector -->
          <div class="md:hidden mb-6">
            <label for="tool-select" class="text-sm font-medium mb-1 block">Select tool</label>
            <select
              id="tool-select"
              :value="selectedToolId ?? ''"
              @change="selectTool(($event.target as HTMLSelectElement).value)"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
            >
              <option v-for="tool in toolsWithSettings()" :key="tool.tool_name" :value="tool.tool_name">
                {{ tool.display_name || tool.tool_name }}
              </option>
            </select>
          </div>

          <!-- Header -->
          <div class="mb-6">
            <h1 class="text-lg font-semibold">Global Settings</h1>
            <p class="text-sm text-muted-foreground mt-0.5">
              Configure default settings shared by all agents.
            </p>
          </div>

          <!-- Saved flash -->
          <div
            v-if="savedFlash"
            class="mb-4 rounded-lg bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-700 dark:text-green-300"
          >
            Settings saved.
          </div>

          <!-- Error -->
          <div
            v-if="toolsError"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
          >
            {{ toolsError }}
          </div>

          <!-- Form -->
          <div
            v-if="selectedTool"
            class="rounded-xl border border-border bg-card p-5"
          >
            <h2 class="text-base font-semibold mb-4">
              {{ selectedTool.display_name || selectedTool.tool_name }}
            </h2>
            <ToolSettingsForm
              :tool="selectedTool"
              :initialSettings="serverSettings"
              :saving="saving"
              :error="saveError"
              @save="onSave"
            />
          </div>

          <!-- Tools list view -->
          <div v-else-if="!loadingTools && !toolsError">
            <div class="rounded-xl border border-border bg-card divide-y divide-border">
              <button
                v-for="tool in toolsWithSettings()"
                :key="tool.tool_name"
                @click="selectTool(tool.tool_name)"
                class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-muted/50 transition-colors"
              >
                <div>
                  <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
                  <p class="text-xs text-muted-foreground mt-0.5">{{ tool.settings_schema.length }} settings</p>
                </div>
                <svg class="h-4 w-4 text-muted-foreground shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>
          </div>

        </template>

        <!-- ── LLM Drivers section ─────────────────────────────────────── -->
        <template v-else>

          <!-- Mobile section selector -->
          <div class="md:hidden mb-6 flex gap-2">
            <button
              @click="selectSection('overview')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              ← Overview
            </button>
            <button
              @click="selectSection('tools')"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
            >
              ← Tools
            </button>
          </div>

          <!-- Error -->
          <div
            v-if="llmStore.error"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
          >
            {{ llmStore.error }}
          </div>

          <!-- ── Create form ─────────────────────────────────────────────── -->
          <template v-if="llmViewMode === 'create'">
            <div class="mb-6">
              <h1 class="text-lg font-semibold">New LLM Configuration</h1>
              <p class="text-sm text-muted-foreground mt-0.5">
                Create a new LLM provider configuration.
              </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
              <!-- Name -->
              <div class="mb-5">
                <label for="llm-config-name" class="block text-sm font-medium mb-1.5">Name</label>
                <input
                  id="llm-config-name"
                  v-model="formName"
                  type="text"
                  placeholder="My OpenAI Config"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                />
              </div>

              <!-- Driver selector (starts empty — user must pick first) -->
              <div class="mb-5">
                <label for="llm-driver-select" class="block text-sm font-medium mb-1.5">Driver</label>
                <select
                  id="llm-driver-select"
                  v-model="formDriverClass"
                  @change="onDriverChange"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                >
                  <option value="">— Select a driver —</option>
                  <option v-for="driver in llmStore.drivers" :key="driver.name" :value="driver.name">
                    {{ driver.display_name }} ({{ driver.name }})
                  </option>
                </select>
              </div>

              <!-- Set as default -->
              <div class="mb-5">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    v-model="formSetAsDefault"
                    class="rounded border-border text-primary focus:ring-primary"
                  />
                  <span class="text-sm font-medium">Set as default</span>
                </label>
                <p class="text-xs text-muted-foreground mt-1 ml-6">
                  The default config is used by all agents that don't have a custom LLM config assigned.
                </p>
              </div>

              <!-- Settings (only visible after driver is selected) -->
              <div v-if="formDriverClass && activeDriver">
                <h3 class="text-sm font-semibold mb-3">Settings</h3>
                <ToolSettingsForm
                  :tool="{ tool_class: activeDriver.driver_class, tool_name: activeDriver.name, display_name: activeDriver.display_name, settings_schema: activeDriver.settings_schema }"
                  :initialSettings="formSettings"
                  :saving="savingLLM"
                  :error="saveLLMError"
                  @save="(s) => { formSettings = s; submitCreate() }"
                />
              </div>
              <div v-else-if="formDriverClass && !activeDriver" class="text-sm text-muted-foreground py-4">
                Unknown driver. Please select a valid driver.
              </div>
              <div v-else class="text-sm text-muted-foreground py-4">
                Select a driver above to see available settings fields.
              </div>

              <div class="mt-4 flex justify-end">
                <button
                  @click="cancelLLMForm"
                  class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </template>

          <!-- ── Edit form ───────────────────────────────────────────────── -->
          <template v-else-if="llmViewMode === 'edit' && selectedConfig && activeDriver">
            <div class="mb-6">
              <h1 class="text-lg font-semibold">{{ selectedConfig.name }}</h1>
              <p class="text-sm text-muted-foreground mt-0.5">
                {{ selectedConfig.driver_display_name }}
              </p>
            </div>

            <!-- Saved flash -->
            <div
              v-if="savedLLMFlash"
              class="mb-4 rounded-lg bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-700 dark:text-green-300"
            >
              Configuration saved.
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
              <!-- Name display -->
              <div class="mb-5">
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-1">Name</p>
                <p class="text-sm font-medium">{{ selectedConfig.name }}</p>
              </div>

              <!-- Driver display -->
              <div class="mb-5">
                <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-1">Driver</p>
                <p class="text-sm">{{ selectedConfig.driver_display_name }}</p>
              </div>

              <!-- Settings form -->
              <div class="mb-5">
                <h3 class="text-sm font-semibold mb-3">Settings</h3>
                <ToolSettingsForm
                  :tool="{ tool_class: activeDriver.driver_class, tool_name: activeDriver.name, display_name: activeDriver.display_name, settings_schema: activeDriver.settings_schema }"
                  :initialSettings="serverSettingsLLM"
                  :saving="savingLLM"
                  :error="saveLLMError"
                  @save="(s) => { formSettings = s; submitEdit() }"
                />
              </div>

              <!-- Actions -->
              <div class="flex items-center justify-between gap-4 pt-4 border-t border-border">
                <div class="flex gap-2">
                  <button
                    v-if="!selectedConfig.is_default"
                    @click="submitSetDefault"
                    :disabled="savingLLM"
                    class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-3 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors disabled:opacity-50"
                  >
                    Set as default
                  </button>
                  <span
                    v-else
                    class="inline-flex h-9 items-center justify-center rounded-lg border border-primary/30 bg-primary/10 px-3 text-sm font-medium text-primary"
                  >
                    Default
                  </span>
                </div>
                <button
                  @click="submitDelete"
                  :disabled="savingLLM"
                  class="inline-flex h-9 items-center justify-center rounded-lg border border-destructive/30 bg-destructive/10 px-3 text-sm font-medium text-destructive hover:bg-destructive/20 transition-colors disabled:opacity-50"
                >
                  Delete
                </button>
              </div>
            </div>

            <!-- Metadata -->
            <div class="mt-4 text-xs text-muted-foreground">
              <p>Created {{ formatDate(selectedConfig.created_at) }}</p>
              <p>Updated {{ formatDate(selectedConfig.updated_at) }}</p>
            </div>
          </template>

          <!-- ── List view ───────────────────────────────────────────────── -->
          <template v-else-if="llmViewMode === 'list' && llmStore.configs.length > 0">
            <div class="mb-6">
              <h1 class="text-lg font-semibold">LLM Providers</h1>
              <p class="text-sm text-muted-foreground mt-0.5">
                Manage your LLM provider configurations.
              </p>
            </div>
            <div class="rounded-xl border border-border bg-card divide-y divide-border">
              <button
                v-for="config in llmStore.configs"
                :key="config.id"
                @click="selectConfig(config)"
                class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-muted/50 transition-colors"
              >
                <div>
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-medium">{{ config.name }}</p>
                    <span
                      v-if="config.is_default"
                      class="text-xs rounded-full bg-primary/10 text-primary px-1.5 py-0.5 font-medium"
                    >
                      Default
                    </span>
                  </div>
                  <p class="text-xs text-muted-foreground mt-0.5">{{ config.driver_display_name }}</p>
                </div>
                <svg class="h-4 w-4 text-muted-foreground shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>
            <div class="mt-4 flex justify-end">
              <button
                @click="startCreate"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
              >
                + Add New
              </button>
            </div>
          </template>

          <!-- ── Empty state ─────────────────────────────────────────────── -->
          <template v-else-if="llmStore.configs.length === 0">
            <div class="rounded-xl border border-border bg-card p-8 text-center">
              <p class="text-sm text-muted-foreground mb-4">
                No LLM configuration selected.
              </p>
              <button
                @click="startCreate"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
              >
                Create your first configuration
              </button>
            </div>
          </template>

        </template>
      </main>
    </div>
  </div>
</template>