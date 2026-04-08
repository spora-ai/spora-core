<script setup lang="ts">
/**
 * SettingsSidebar — collapsible submenu sidebar for Global Settings.
 *
 * All navigation is driven by vue-router. Active state is derived from the
 * current route and query params — no props needed for selection state.
 */
import { useRoute, useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { ChevronRight } from 'lucide-vue-next'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  allTools: ToolSchema[]
  loadingTools: boolean
}>()

const route = useRoute()
const router = useRouter()
const llmStore = useLlmConfigsStore()

const isOverview = () => route.name === 'settings-overview'
const isTools = () => route.name === 'settings-tools'
const isLLM = () => route.name === 'settings-llm'

const selectedToolId = () => route.query.tool as string | undefined

function configurableTools(): ToolSchema[] {
  return props.allTools.filter((t) => t.settings_schema.length > 0)
}

function selectTool(toolName: string): void {
  router.push({ name: 'settings-tools', query: { tool: toolName } })
}

function selectConfig(configId: number): void {
  router.push({ name: 'settings-llm', query: { config: String(configId) } })
}

function startCreate(): void {
  router.push({ name: 'settings-llm', query: { create: '1' } })
}
</script>

<template>
  <aside class="w-64 border-r border-border shrink-0 overflow-y-auto hidden md:block">
    <div class="p-4">
      <h2 class="text-sm font-semibold text-muted-foreground uppercase tracking-wider mb-3">
        Settings
      </h2>
      <ul class="flex flex-col gap-0.5">

        <!-- Overview -->
        <li>
          <button
            @click="router.push({ name: 'settings-overview' })"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
            :class="
              isOverview()
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            Overview
          </button>
        </li>

        <!-- Tools -->
        <li>
          <button
            @click="router.push({ name: 'settings-tools' })"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
            :class="
              isTools()
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            <span>Tools</span>
            <ChevronRight
              class="h-3.5 w-3.5 transition-transform"
              :class="isTools() ? 'rotate-90' : ''"
            />
          </button>

          <!-- Tools submenu -->
          <div v-if="isTools()" class="ml-3 mt-1 border-l border-border pl-3">
            <ul class="flex flex-col gap-0.5">
              <li v-if="loadingTools">
                <p class="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
              </li>
              <li v-else-if="configurableTools().length === 0">
                <p class="px-3 py-2 text-xs text-muted-foreground">No configurable tools.</p>
              </li>
              <li v-for="tool in configurableTools()" :key="tool.tool_name">
                <button
                  @click="selectTool(tool.tool_name)"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                  :class="
                    selectedToolId() === tool.tool_name
                      ? 'bg-primary/10 text-primary font-medium'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                  "
                >
                  {{ tool.display_name || tool.tool_name }}
                </button>
              </li>
            </ul>
          </div>
        </li>

        <!-- LLM -->
        <li>
          <button
            @click="router.push({ name: 'settings-llm' })"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
            :class="
              isLLM()
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            <span>LLM</span>
            <ChevronRight
              class="h-3.5 w-3.5 transition-transform"
              :class="isLLM() ? 'rotate-90' : ''"
            />
          </button>

          <!-- LLM submenu -->
          <div v-if="isLLM()" class="ml-3 mt-1 border-l border-border pl-3">
            <ul class="flex flex-col gap-0.5">
              <li v-if="llmStore.loadingConfigs">
                <p class="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
              </li>
              <li v-for="config in llmStore.configs" :key="config.id">
                <button
                  @click="selectConfig(config.id)"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors truncate"
                  :class="
                    route.query.config === String(config.id)
                      ? 'bg-primary/10 text-primary font-medium'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                  "
                >
                  {{ config.name }}
                </button>
              </li>
              <li>
                <button
                  @click="startCreate"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm text-primary hover:bg-primary/10 transition-colors mt-1"
                >
                  + Add New
                </button>
              </li>
            </ul>
          </div>
        </li>

      </ul>
    </div>
  </aside>
</template>
