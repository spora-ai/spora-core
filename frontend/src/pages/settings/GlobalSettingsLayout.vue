<script setup lang="ts">
import { ref, provide, onMounted } from 'vue'
import { api } from '@/api/client'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import SettingsSidebar from '@/components/settings/SettingsSidebar.vue'
import Icon from '@/components/ui/Icon.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const llmStore = useLlmConfigsStore()

// Tools: loaded here, provided to child pages via inject
const allTools = ref<ToolSchema[]>([])
const loadingTools = ref(false)

provide('settingsTools', { allTools, loadingTools })

const sidebarOpen = ref(false)

function openSidebar(): void {
  sidebarOpen.value = true
}

function closeSidebar(): void {
  sidebarOpen.value = false
}

onMounted(async () => {
  // Load tools and LLM data in parallel — all sub-pages benefit immediately.
  // llmStore.ensure() is idempotent: re-entering any sub-page won't re-fetch.
  loadingTools.value = true
  await Promise.all([
    api.get<{ tools: ToolSchema[] }>('/tools').then((r) => {
      allTools.value = r.tools
    }).catch(() => { /* non-fatal */ }),
    llmStore.ensure(),
  ])
  loadingTools.value = false
})
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />
    <div class="flex-1 flex">
      <!-- Mobile sidebar overlay -->
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 md:hidden"
        @click="closeSidebar"
      >
        <div class="absolute inset-0 bg-black/50" />
        <SettingsSidebar
          :allTools="allTools"
          :loadingTools="loadingTools"
          mobile-open
          class="absolute left-0 top-0 h-full"
          @close="closeSidebar"
        />
      </div>

      <!-- Desktop sidebar -->
      <SettingsSidebar
        :allTools="allTools"
        :loadingTools="loadingTools"
        class="hidden md:flex"
      />

      <!-- Main content -->
      <main class="flex-1 w-full px-4 py-8">
        <!-- Mobile sidebar toggle -->
        <div class="flex items-center gap-3 mb-6 md:hidden">
          <button
            @click="openSidebar"
            class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
            title="Show settings menu"
          >
            <Icon name="menu" />
          </button>
          <h1 class="text-xl font-bold">Settings</h1>
        </div>
        <RouterView />
      </main>
    </div>
  </div>
</template>
