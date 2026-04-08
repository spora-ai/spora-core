<script setup lang="ts">
import { inject, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { ToolSchema } from '@/composables/useToolSettings'
import type { Ref } from 'vue'

const router = useRouter()
const llmStore = useLlmConfigsStore()

const { allTools, loadingTools } = inject('settingsTools') as {
  allTools: Ref<ToolSchema[]>
  loadingTools: Ref<boolean>
}

const configurableTools = computed(() => allTools.value.filter((t) => t.settings_schema.length > 0))

function goToTool(toolName: string): void {
  router.push({ name: 'settings-tools', query: { tool: toolName } })
}
</script>

<template>
  <!-- Mobile nav -->
  <div class="md:hidden mb-6 flex gap-2">
    <button
      @click="router.push({ name: 'settings-tools' })"
      class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
    >
      Tools →
    </button>
    <button
      @click="router.push({ name: 'settings-llm' })"
      class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
    >
      LLM →
    </button>
  </div>

  <div class="mb-6">
    <h1 class="text-lg font-semibold">Global Settings</h1>
    <p class="text-sm text-muted-foreground mt-0.5">Manage your tools and LLM provider configurations.</p>
  </div>

  <!-- Tools overview -->
  <div class="mb-6">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold">Tools</h2>
      <button
        @click="router.push({ name: 'settings-tools' })"
        class="text-xs text-primary hover:text-primary/80 font-medium"
      >
        View all →
      </button>
    </div>
    <div v-if="loadingTools" class="text-sm text-muted-foreground">Loading…</div>
    <div
      v-else-if="configurableTools.length === 0"
      class="rounded-xl border border-border bg-card p-5 text-sm text-muted-foreground"
    >
      No configurable tools available.
    </div>
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <button
        v-for="tool in configurableTools.slice(0, 6)"
        :key="tool.tool_name"
        @click="goToTool(tool.tool_name)"
        class="rounded-xl border border-border bg-card p-4 text-left hover:border-primary/50 hover:bg-muted/50 transition-colors"
      >
        <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
        <p class="text-xs text-muted-foreground mt-0.5">{{ tool.settings_schema.length }} settings</p>
      </button>
    </div>
  </div>

  <!-- LLM overview -->
  <div>
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold">LLM Providers</h2>
      <button
        @click="router.push({ name: 'settings-llm' })"
        class="text-xs text-primary hover:text-primary/80 font-medium"
      >
        Manage →
      </button>
    </div>
    <div v-if="llmStore.loadingConfigs" class="text-sm text-muted-foreground">Loading…</div>
    <div
      v-else-if="llmStore.configs.length === 0"
      class="rounded-xl border border-border bg-card p-5 text-sm text-muted-foreground"
    >
      No LLM configurations yet.
      <button
        @click="router.push({ name: 'settings-llm' })"
        class="ml-1 text-primary hover:text-primary/80 font-medium"
      >
        Add one →
      </button>
    </div>
    <div v-else class="rounded-xl border border-border bg-card divide-y divide-border">
      <button
        v-for="config in llmStore.configs.slice(0, 3)"
        :key="config.id"
        type="button"
        @click="router.push({ name: 'settings-llm', query: { config: String(config.id) } })"
        class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-muted/50 transition-colors"
      >
        <div class="flex items-center gap-2">
          <p class="text-sm font-medium">{{ config.name }}</p>
          <span
            v-if="config.is_default"
            class="text-xs rounded-full bg-primary/10 text-primary px-1.5 py-0.5 font-medium"
          >
            Default
          </span>
        </div>
        <p class="text-xs text-muted-foreground">{{ config.driver_display_name }}</p>
      </button>
    </div>
  </div>
</template>
