<script setup lang="ts">
import { ref, computed, inject, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToolSettings } from '@/composables/useToolSettings'
import ToolList from '@/components/settings/tools/ToolList.vue'
import ToolSettingsPanel from '@/components/settings/tools/ToolSettingsPanel.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { ToolSchema } from '@/composables/useToolSettings'
import type { Ref } from 'vue'

const route = useRoute()
const router = useRouter()

const { allTools, loadingTools } = inject('settingsTools') as {
  allTools: Ref<ToolSchema[]>
  loadingTools: Ref<boolean>
}

const { getSettings } = useToolSettings()

// null = list view, string = settings form for that tool
const selectedToolId = ref<string | null>(null)

const selectedTool = computed<ToolSchema | null>(
  () => allTools.value.find((t) => t.tool_name === selectedToolId.value) ?? null,
)
const serverSettings = ref<Record<string, string>>({})
const loadError = ref<string | null>(null)

async function selectTool(toolName: string): Promise<void> {
  loadError.value = null
  try {
    serverSettings.value = await getSettings(toolName)
  } catch {
    serverSettings.value = {}
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
  <!-- Mobile nav -->
  <div class="md:hidden mb-6 flex gap-2">
    <button
      @click="router.push({ name: 'settings-overview' })"
      class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
    >
      ← Overview
    </button>
    <button
      @click="router.push({ name: 'settings-llm' })"
      class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
    >
      LLM →
    </button>
  </div>

  <div v-if="loadingTools" class="text-sm text-muted-foreground">Loading…</div>

  <!-- Settings panel (tool selected) -->
  <template v-else-if="selectedTool">
    <AlertBanner v-if="loadError" type="error" :message="loadError" class="mb-4" />
    <ToolSettingsPanel
      :tool="selectedTool"
      :initialSettings="serverSettings"
      @back="goBack"
    />
    <!-- Mobile: keep select visible for switching tools -->
    <div class="md:hidden mt-6">
      <ToolList
        :tools="allTools"
        :selectedToolId="selectedToolId"
        @select="selectTool"
      />
    </div>
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
    <ToolList
      v-else
      :tools="allTools"
      :selectedToolId="null"
      @select="selectTool"
    />
  </template>
</template>
