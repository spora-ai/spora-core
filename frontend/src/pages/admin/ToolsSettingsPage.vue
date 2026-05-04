<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useGlobalSettingsStore } from '@/stores/globalSettings'
import { useAdminAuth } from '@/composables/useAdminAuth'
import AdminSection from '@/components/admin/AdminSection.vue'
import AdminForbidden from '@/components/admin/AdminForbidden.vue'
import ToolSettingsPanel from '@/components/settings/tools/ToolSettingsPanel.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const route = useRoute()
const router = useRouter()

const { isAdmin } = useAdminAuth()
const store = useGlobalSettingsStore()

// null = list view, string = settings form for that tool
const selectedToolId = ref<string | null>(null)

const selectedTool = computed<ToolSchema | null>(
  () => store.allTools.find((t) => t.tool_name === selectedToolId.value) ?? null,
)

const configurableTools = computed(() =>
  store.allTools.filter((t) => t.settings_schema.length > 0),
)

function hasGlobalSettings(tool: ToolSchema): boolean {
  const settings = store.toolSettings[tool.tool_name]
  return settings !== undefined && Object.values(settings).some((v) => v !== '' && v !== null)
}

async function selectTool(toolName: string): Promise<void> {
  selectedToolId.value = toolName
  router.replace({ query: { tool: toolName } })
}

function goBack(): void {
  selectedToolId.value = null
  router.replace({ query: {} })
}

// Sync with ?tool= query param (sidebar clicks, direct URLs, browser back)
watch(
  () => route.query.tool as string | undefined,
  (toolName) => {
    if (toolName && toolName !== selectedToolId.value) {
      selectTool(toolName)
    } else if (!toolName && selectedToolId.value !== null) {
      selectedToolId.value = null
    }
  },
)

onMounted(async () => {
  await store.loadTools()
  await store.loadToolSettings()
  const queryTool = route.query.tool as string | undefined
  if (queryTool) selectTool(queryTool)
})

// ── Category helpers ────────────────────────────────────────────────────────────

function toLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

const toolsByCategory = computed(() => {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of configurableTools.value) {
    const cat = tool.category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
})

const sortedCategories = computed(() =>
  Object.keys(toolsByCategory.value).sort((a, b) => toLabel(a).localeCompare(toLabel(b))),
)

const collapsedCategories = ref<Record<string, boolean>>({})
</script>

<template>
  <AdminForbidden v-if="!isAdmin" />

  <AdminSection v-else title="Tool Defaults" description="Configure global default settings for all tools.">
    <AlertBanner v-if="store.toolError" type="error" :message="store.toolError" class="mb-4" />

    <div v-if="store.loadingTools" class="text-sm text-muted-foreground">Loading tools…</div>

    <!-- Settings panel (tool selected) -->
    <template v-else-if="selectedTool">
      <ToolSettingsPanel
        :tool="selectedTool"
        mode="global"
        @back="goBack"
      />
    </template>

    <!-- Tool list (default / overview) -->
    <template v-else>
      <div class="mb-6">
        <h1 class="text-lg font-semibold">Tool Defaults</h1>
        <p class="text-sm text-muted-foreground mt-0.5">Select a tool to configure its global default settings.</p>
      </div>
      <div
        v-if="configurableTools.length === 0"
        class="rounded-xl border border-border bg-card p-5 text-sm text-muted-foreground"
      >
        No configurable tools available.
      </div>
      <div
        v-else
        class="rounded-xl border border-border bg-card divide-y divide-border"
      >
        <template v-for="cat in sortedCategories" :key="cat">
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
          <template v-if="!collapsedCategories[cat]">
            <div
              v-for="tool in toolsByCategory[cat]"
              :key="tool.tool_class"
              class="px-5 py-3.5 flex items-center justify-between cursor-pointer hover:bg-muted/20 transition-colors"
              @click="selectTool(tool.tool_name)"
            >
              <div class="flex items-center gap-3">
                <span class="text-sm font-medium">{{ tool.display_name ?? tool.tool_name }}</span>
                <span
                  v-if="hasGlobalSettings(tool)"
                  class="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400"
                >
                  <Icon name="check-circle" class="h-3.5 w-3.5" />
                  Global default
                </span>
              </div>
              <Icon name="chevron-right" class="h-4 w-4 text-muted-foreground" />
            </div>
          </template>
        </template>
      </div>
    </template>
  </AdminSection>
</template>
