<script setup lang="ts">
import { ref, computed, inject, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToolSettings } from '@/composables/useToolSettings'
import ToolSettingsPanel from '@/components/settings/tools/ToolSettingsPanel.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema } from '@/composables/useToolSettings'
import type { Ref } from 'vue'

const route = useRoute()
const router = useRouter()

const { allTools, loadingTools } = inject('settingsTools') as {
  allTools: Ref<ToolSchema[]>
  loadingTools: Ref<boolean>
}

const { getGlobalSettings } = useToolSettings()

// null = list view, string = settings form for that tool
const selectedToolId = ref<string | null>(null)

const selectedTool = computed<ToolSchema | null>(
  () => allTools.value.find((t) => t.tool_name === selectedToolId.value) ?? null,
)
const globalDefaults = ref<Record<string, string>>({})
const loadError = ref<string | null>(null)

// ── Tools by category ────────────────────────────────────────────────────────

function toLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

const toolsByCategory = computed(() => {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of allTools.value) {
    const cat = tool.category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
})

const sortedCategories = computed(() =>
  Object.keys(toolsByCategory.value).sort((a, b) => toLabel(a).localeCompare(toLabel(b))),
)

// ── Category collapse state ───────────────────────────────────────────────────

const collapsedCategories = ref<Record<string, boolean>>({})

async function selectTool(toolName: string): Promise<void> {
  loadError.value = null
  try {
    globalDefaults.value = await getGlobalSettings(toolName)
  } catch {
    globalDefaults.value = {}
  }
  selectedToolId.value = toolName
  router.replace({ name: 'settings-tools', query: { tool: toolName } })
}

function goBack(): void {
  selectedToolId.value = null
  router.replace({ name: 'settings-tools' })
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

onMounted(() => {
  const queryTool = route.query.tool as string | undefined
  if (queryTool) selectTool(queryTool)
  // No auto-select: default is the list view
})
</script>

<template>
  <div v-if="loadingTools" class="text-sm text-muted-foreground">Loading…</div>

  <!-- Settings panel (tool selected) -->
  <template v-else-if="selectedTool">
    <AlertBanner v-if="loadError" type="error" :message="loadError" class="mb-4" />
    <ToolSettingsPanel
      :tool="selectedTool"
      :globalDefaults="globalDefaults"
      mode="user"
      @back="goBack"
    />
  </template>

  <!-- Tool list (default / overview) -->
  <template v-else>
    <div class="mb-6">
      <h1 class="text-lg font-semibold">Tool Settings</h1>
      <p class="text-sm text-muted-foreground mt-0.5">Select a tool to configure its default settings.</p>
    </div>
    <AlertBanner v-if="loadError" type="error" :message="loadError" class="mb-4" />
    <div
      v-if="allTools.filter(t => t.settings_schema.length > 0).length === 0"
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
            v-for="tool in toolsByCategory[cat].filter(t => t.settings_schema.length > 0)"
            :key="tool.tool_class"
            class="px-5 py-3.5 flex items-center justify-between cursor-pointer hover:bg-muted/20 transition-colors"
            @click="selectTool(tool.tool_name)"
          >
            <div class="flex items-center gap-3">
              <span class="text-sm font-medium">{{ tool.display_name ?? tool.tool_name }}</span>
            </div>
            <Icon name="chevron-right" class="h-4 w-4 text-muted-foreground" />
          </div>
        </template>
      </template>
    </div>
  </template>
</template>
