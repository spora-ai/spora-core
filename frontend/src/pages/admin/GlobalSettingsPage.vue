<script setup lang="ts">
/**
 * GlobalSettingsPage — admin page for global LLM and tool defaults.
 * Route: /admin/settings
 *
 * Contains two sections:
 *   LLM Drivers — per-driver global default settings (stored as the admin's own configs)
 *   Tool Defaults — global default settings for core tools
 */
import { ref, computed, onMounted } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { useAuthStore } from '@/stores/auth'
import { useGlobalSettingsStore } from '@/stores/globalSettings'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import type { LLMDriverInfo } from '@/types/llmConfig'
import type { ToolSchema } from '@/composables/useToolSettings'
import { useToast } from '@/composables/useToast'

const auth = useAuthStore()
const store = useGlobalSettingsStore()
const toast = useToast()

const isForbidden = computed(() => {
  if (!auth.user) return false
  return !auth.user.is_admin
})

const collapsedCategories = ref<Record<string, boolean>>({})

onMounted(async () => {
  try {
    await store.loadAll()
  } catch {
    toast.error('Failed to load settings.')
  }
})

async function saveDriver(driver: LLMDriverInfo): Promise<void> {
  try {
    await store.saveDriverSettings(driver)
    toast.success(`${driver.display_name} settings saved.`)
  } catch {
    toast.error(store.driverError ?? 'Failed to save driver settings.')
  }
}

async function saveTool(tool: ToolSchema): Promise<void> {
  try {
    await store.saveToolSettings(tool)
    toast.success(`${tool.display_name || tool.tool_name} settings saved.`)
  } catch {
    toast.error(store.toolError ?? 'Failed to save tool settings.')
  }
}

function toLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

const toolsByCategory = computed(() => {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of store.allTools) {
    if (tool.settings_schema.length === 0) continue
    const cat = tool.category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
})

const sortedCategories = computed(() =>
  Object.keys(toolsByCategory.value).sort((a, b) => toLabel(a).localeCompare(toLabel(b))),
)
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <template v-if="isForbidden">
      <main class="flex-1 flex flex-col items-center justify-center text-center px-4">
        <Icon name="warning" class="h-12 w-12 text-muted-foreground mb-4" />
        <h1 class="text-lg font-semibold">Forbidden</h1>
        <p class="text-sm text-muted-foreground mt-1">You need admin privileges to access this page.</p>
      </main>
    </template>

    <template v-else>
      <main class="flex-1 px-6 py-8">
        <div class="max-w-3xl mx-auto">
          <!-- Header -->
          <div class="mb-8">
            <h1 class="text-xl font-semibold">Global Settings</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Configure system-wide LLM drivers and tool defaults.</p>
          </div>

          <!-- LLM Drivers Section -->
          <section class="mb-10">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-base font-semibold">LLM Drivers</h2>
              <span class="text-xs text-muted-foreground">Default settings for each driver</span>
            </div>

            <div v-if="store.loadingDrivers" class="flex items-center justify-center py-8 text-sm text-muted-foreground">
              Loading drivers…
            </div>

            <div v-else-if="store.drivers.length === 0" class="rounded-xl border border-border p-6 text-center text-sm text-muted-foreground">
              No LLM drivers registered.
            </div>

            <div v-else class="flex flex-col gap-4">
              <div
                v-for="driver in store.drivers"
                :key="driver.driver_class"
                class="rounded-xl border border-border bg-card p-5"
              >
                <div class="flex items-start justify-between mb-4 gap-4">
                  <div>
                    <h3 class="text-sm font-semibold">{{ driver.display_name }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ driver.name }}</p>
                  </div>
                </div>

                <ToolSettingsForm
                  :tool="{ tool_class: driver.driver_class, tool_name: driver.name, display_name: driver.display_name, category: '', settings_schema: driver.settings_schema, operations: [] }"
                  :initialSettings="store.driverSettings[driver.name] ?? {}"
                  :saving="store.savingDriver === driver.name"
                  :error="store.driverError && store.savingDriver === driver.name ? store.driverError : null"
                  @save="(settings) => { store.driverSettings[driver.name] = settings }"
                />

                <div class="flex items-center justify-between mt-4">
                  <p v-if="store.driverError && store.savingDriver === driver.name" class="text-xs text-destructive">{{ store.driverError }}</p>
                  <span v-else />
                  <button
                    @click="saveDriver(driver)"
                    :disabled="store.savingDriver !== null && store.savingDriver !== driver.name"
                    class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                  >
                    {{ store.savingDriver === driver.name ? 'Saving…' : 'Save' }}
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Tool Defaults Section -->
          <section>
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-base font-semibold">Tool Defaults</h2>
              <span class="text-xs text-muted-foreground">Global default settings for tools</span>
            </div>

            <div v-if="store.loadingTools" class="flex items-center justify-center py-8 text-sm text-muted-foreground">
              Loading tools…
            </div>

            <div v-else-if="sortedCategories.length === 0" class="rounded-xl border border-border p-6 text-center text-sm text-muted-foreground">
              No configurable tools found.
            </div>

            <div v-else class="rounded-xl border border-border overflow-hidden">
              <template v-for="cat in sortedCategories" :key="cat">
                <!-- Category header -->
                <div
                  class="px-5 py-3 flex items-center justify-between bg-muted/30 cursor-pointer select-none"
                  @click="collapsedCategories[cat] = !collapsedCategories[cat]"
                >
                  <h3 class="text-sm font-medium">{{ toLabel(cat) }}</h3>
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">{{ toolsByCategory[cat].length }}</span>
                    <Icon
                      name="chevron-down"
                      :class="`h-4 w-4 text-muted-foreground transition-transform ${collapsedCategories[cat] ? '-rotate-90' : ''}`"
                    />
                  </div>
                </div>

                <!-- Tools in category -->
                <template v-if="!collapsedCategories[cat]">
                  <div
                    v-for="tool in toolsByCategory[cat]"
                    :key="tool.tool_class"
                    class="border-t border-border p-5"
                  >
                    <div class="flex items-start justify-between mb-3 gap-4">
                      <div>
                        <h4 class="text-sm font-semibold">{{ tool.display_name || tool.tool_name }}</h4>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ tool.tool_name }}</p>
                      </div>
                    </div>

                    <ToolSettingsForm
                      :tool="tool"
                      :initialSettings="store.toolSettings[tool.tool_name] ?? {}"
                      :saving="store.savingTool === tool.tool_name"
                      :error="store.toolError && store.savingTool === tool.tool_name ? store.toolError : null"
                      @save="(settings) => { store.toolSettings[tool.tool_name] = settings }"
                    />

                    <div class="flex items-center justify-between mt-4">
                      <p v-if="store.toolError && store.savingTool === tool.tool_name" class="text-xs text-destructive">{{ store.toolError }}</p>
                      <span v-else />
                      <button
                        @click="saveTool(tool)"
                        :disabled="store.savingTool !== null && store.savingTool !== tool.tool_name"
                        class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                      >
                        {{ store.savingTool === tool.tool_name ? 'Saving…' : 'Save' }}
                      </button>
                    </div>
                  </div>
                </template>
              </template>
            </div>
          </section>

        </div>
      </main>
    </template>
  </div>
</template>