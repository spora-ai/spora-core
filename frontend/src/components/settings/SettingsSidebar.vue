<script setup lang="ts">
/**
 * SettingsSidebar — collapsible submenu sidebar for Global Settings.
 *
 * Shows:
 * - Main nav: Tools, LLM
 * - Tools submenu (when Tools active): list of configurable tools
 * - LLM submenu (when LLM active): list of configs + New button
 */
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { ToolSchema } from '@/composables/useToolSettings'
import type { LLMConfigResource } from '@/types/llmConfig'

const props = defineProps<{
  selectedSection: 'overview' | 'tools' | 'llm'
  allTools: ToolSchema[]
  loadingTools: boolean
  selectedToolId: string | null
  selectedConfigId: number | null
}>()

const emit = defineEmits<{
  'update:selectedSection': [section: 'overview' | 'tools' | 'llm']
  selectTool: [toolName: string]
  selectConfig: [config: LLMConfigResource]
  startCreate: []
}>()

const llmStore = useLlmConfigsStore()

function toolsWithSettings(): ToolSchema[] {
  return props.allTools.filter((t) => t.settings_schema.length > 0)
}
</script>

<template>
  <aside class="w-64 border-r border-border shrink-0 overflow-y-auto hidden md:block">
    <div class="p-4">
      <h2 class="text-sm font-semibold text-muted-foreground uppercase tracking-wider mb-3">
        Settings
      </h2>
      <ul class="flex flex-col gap-0.5">
        <li>
          <button
            @click="emit('update:selectedSection', 'overview')"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
            :class="
              selectedSection === 'overview'
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            <span>Overview</span>
          </button>
        </li>

        <li>
          <button
            @click="emit('update:selectedSection', 'tools')"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
            :class="
              selectedSection === 'tools'
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            <span>Tools</span>
            <svg
              class="h-3.5 w-3.5 transition-transform"
              :class="selectedSection === 'tools' ? 'rotate-90' : ''"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </button>

          <!-- Tools submenu -->
          <div v-if="selectedSection === 'tools'" class="ml-3 mt-1 border-l border-border pl-3">
            <ul class="flex flex-col gap-0.5">
              <li v-if="loadingTools">
                <p class="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
              </li>
              <li v-else-if="toolsWithSettings().length === 0">
                <p class="px-3 py-2 text-xs text-muted-foreground">No configurable tools.</p>
              </li>
              <li v-for="tool in toolsWithSettings()" :key="tool.tool_name">
                <button
                  @click="emit('selectTool', tool.tool_name)"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                  :class="
                    selectedToolId === tool.tool_name
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

        <li>
          <button
            @click="emit('update:selectedSection', 'llm')"
            class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
            :class="
              selectedSection === 'llm'
                ? 'bg-primary text-primary-foreground font-medium'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            "
          >
            <span>LLM</span>
            <svg
              class="h-3.5 w-3.5 transition-transform"
              :class="selectedSection === 'llm' ? 'rotate-90' : ''"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </button>

          <!-- LLM submenu -->
          <div v-if="selectedSection === 'llm'" class="ml-3 mt-1 border-l border-border pl-3">
            <ul class="flex flex-col gap-0.5">
              <li v-if="llmStore.loadingConfigs">
                <p class="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
              </li>
              <li v-for="config in llmStore.configs" :key="config.id">
                <button
                  @click="emit('selectConfig', config)"
                  class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                  :class="
                    selectedConfigId === config.id
                      ? 'bg-primary/10 text-primary font-medium'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                  "
                >
                  <span class="truncate">{{ config.name }}</span>
                </button>
              </li>
              <li>
                <button
                  @click="emit('startCreate')"
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
