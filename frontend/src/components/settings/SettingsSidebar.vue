<script setup lang="ts">
/**
 * SettingsSidebar — collapsible submenu sidebar for Global Settings.
 *
 * All navigation is driven by vue-router. Active state is derived from the
 * current route and query params — no props needed for selection state.
 */
import { computed, useAttrs } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useAuthStore } from '@/stores/auth'
import { ChevronRight, X } from 'lucide-vue-next'
import type { ToolSchema } from '@/composables/useToolSettings'

const attrs = useAttrs()

const props = defineProps<{
  allTools: ToolSchema[]
  loadingTools: boolean
  mobileOpen?: boolean
}>()

const emit = defineEmits<{
  close: () => void
}>()

const route = useRoute()
const router = useRouter()
const llmStore = useLlmConfigsStore()
const auth = useAuthStore()

const isOverview = () => route.name === 'settings-overview'
const isTools = () => route.name === 'settings-tools'
const isLLM = () => route.name === 'settings-llm'
const isAdminUsers = () => route.name === 'settings-admin-users'
const isAdminDrivers = () => route.name === 'settings-admin-drivers'
const isAdminTools = () => route.name === 'settings-admin-tools'
const isAdminMailTemplates = () => route.name === 'settings-admin-mail-templates'

const isAdmin = computed(() => auth.user?.roles?.includes('ADMIN') ?? false)

const selectedToolId = () => route.query.tool as string | undefined

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

// @ts-ignore TS is confused by emit() in arrow fn passed to another function
const emitCloseSidebar = () => emit('close')

const closeSidebar = emitCloseSidebar
</script>

<template>
  <!-- Mobile backdrop -->
  <Transition name="fade">
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-40 bg-black/50 md:hidden"
      @click="closeSidebar()"
    />
  </Transition>

  <!-- Sidebar -->
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
    <div class="p-4 pt-0">
      <ul class="flex flex-col gap-0.5">

        <!-- Overview -->
        <li>
          <button
            @click="router.push({ name: 'settings-overview' }); closeSidebar()"
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
            @click="router.push({ name: 'settings-tools' }); closeSidebar()"
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
            @click="router.push({ name: 'settings-llm' }); closeSidebar()"
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

      <!-- Administration section (admin only) -->
      <template v-if="isAdmin">
        <div class="mt-6 pt-4 border-t border-border">
          <h2 class="text-sm font-semibold text-muted-foreground uppercase tracking-wider mb-3">
            Administration
          </h2>
          <ul class="flex flex-col gap-0.5">
            <li>
              <button
                @click="router.push({ name: 'settings-admin-users' }); closeSidebar()"
                class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                :class="
                  isAdminUsers()
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                "
              >
                Users
              </button>
            </li>
            <li>
              <button
                @click="router.push({ name: 'settings-admin-drivers' }); closeSidebar()"
                class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                :class="
                  isAdminDrivers()
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                "
              >
                LLM Drivers
              </button>
            </li>
            <li>
              <button
                @click="router.push({ name: 'settings-admin-tools' }); closeSidebar()"
                class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                :class="
                  isAdminTools()
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                "
              >
                Tool Defaults
              </button>
            </li>
            <li>
              <button
                @click="router.push({ name: 'settings-admin-mail-templates' }); closeSidebar()"
                class="w-full text-left px-3 py-2 rounded-lg text-sm transition-colors"
                :class="
                  isAdminMailTemplates()
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                "
              >
                Mail Templates
              </button>
            </li>
          </ul>
        </div>
      </template>
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
