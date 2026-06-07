<script setup lang="ts">
/**
 * SettingsSidebar — collapsible submenu sidebar for Global Settings.
 *
 * All navigation is driven by vue-router. Active state is derived from the
 * current route and query params. Items are grouped via SettingsNavGroup;
 * each group owns its own collapse state.
 */
import { computed, useAttrs } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useAuthStore } from '@/stores/auth'
import { ChevronRight, X } from 'lucide-vue-next'
import type { ToolSchema } from '@/composables/useToolSettings'
import SettingsNavGroup from './SettingsNavGroup.vue'

const attrs = useAttrs()

const props = defineProps<{
  allTools: ToolSchema[]
  loadingTools: boolean
  mobileOpen?: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const route = useRoute()
const router = useRouter()
const llmStore = useLlmConfigsStore()
const auth = useAuthStore()

const isAdmin = computed(() => auth.user?.roles?.includes('ADMIN') ?? false)

const adminLinks: { name: string; label: string }[] = [
  { name: 'settings-admin-users', label: 'Users' },
  { name: 'settings-admin-drivers', label: 'LLM Drivers' },
  { name: 'settings-admin-tools', label: 'Tool Defaults' },
  { name: 'settings-admin-mail-templates', label: 'Mail Templates' },
]

function configurableTools(): ToolSchema[] {
  return props.allTools.filter((t) => t.settings_schema.length > 0)
}

function selectTool(toolName: string): void {
  router.push({ name: 'settings-tools', query: { tool: toolName } })
  closeSidebar()
}

function selectConfig(configId: number): void {
  router.push({ name: 'settings-llm', query: { config: String(configId) } })
  closeSidebar()
}

function startCreate(): void {
  router.push({ name: 'settings-llm', query: { create: '1' } })
  closeSidebar()
}

function closeSidebar(): void {
  emit('close')
}
</script>

<template>
  <Transition name="fade">
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-40 bg-black/50 md:hidden"
      @click="closeSidebar()"
    />
  </Transition>

  <aside
    class="flex flex-col border-r border-border bg-background shrink-0 overflow-y-auto"
    :class="[
      mobileOpen
        ? 'fixed inset-y-0 left-0 z-50 w-72 shadow-xl md:hidden'
        : 'hidden md:flex w-64'
    ]"
    v-bind="attrs"
  >
    <div class="p-4 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-foreground uppercase tracking-wider">
        Settings
      </h2>
      <button
        v-if="mobileOpen"
        @click="closeSidebar()"
        class="flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors md:hidden"
        title="Close"
      >
        <X class="h-4 w-4" />
      </button>
    </div>

    <div class="p-4 pt-0 flex flex-col gap-6">
      <SettingsNavGroup>
        <ul class="flex flex-col gap-0.5">
          <li>
            <button
              @click="router.push({ name: 'settings-overview' }); closeSidebar()"
              class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
              :class="
                route.name === 'settings-overview'
                  ? 'bg-primary text-primary-foreground font-medium'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              "
            >
              Overview
            </button>
          </li>

          <li>
            <button
              @click="router.push({ name: 'settings-tools' }); closeSidebar()"
              class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
              :class="
                route.name === 'settings-tools'
                  ? 'bg-primary text-primary-foreground font-medium'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              "
            >
              <span>Tools</span>
              <ChevronRight
                class="h-3.5 w-3.5 transition-transform"
                :class="route.name === 'settings-tools' ? 'rotate-90' : ''"
              />
            </button>
            <div v-if="route.name === 'settings-tools'" class="ml-3 mt-1 border-l border-border pl-3">
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
                    class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors truncate"
                    :class="
                      route.query.tool === tool.tool_name
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
              @click="router.push({ name: 'settings-llm' }); closeSidebar()"
              class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center justify-between"
              :class="
                route.name === 'settings-llm'
                  ? 'bg-primary text-primary-foreground font-medium'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              "
            >
              <span>LLM</span>
              <ChevronRight
                class="h-3.5 w-3.5 transition-transform"
                :class="route.name === 'settings-llm' ? 'rotate-90' : ''"
              />
            </button>
            <div v-if="route.name === 'settings-llm'" class="ml-3 mt-1 border-l border-border pl-3">
              <ul class="flex flex-col gap-0.5">
                <li v-if="llmStore.loadingConfigs">
                  <p class="px-3 py-2 text-xs text-muted-foreground">Loading…</p>
                </li>
                <li v-for="config in llmStore.personalConfigs" :key="config.id">
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
      </SettingsNavGroup>

      <SettingsNavGroup v-if="isAdmin" title="Administration">
        <ul class="flex flex-col gap-0.5">
          <li v-for="link in adminLinks" :key="link.name">
            <button
              @click="router.push({ name: link.name }); closeSidebar()"
              class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
              :class="
                route.name === link.name
                  ? 'bg-primary text-primary-foreground font-medium'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              "
            >
              {{ link.label }}
            </button>
          </li>
        </ul>
      </SettingsNavGroup>
    </div>
  </aside>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
