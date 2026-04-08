<script setup lang="ts">
import { ref, provide, onMounted } from 'vue'
import { api } from '@/api/client'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import SettingsSidebar from '@/components/settings/SettingsSidebar.vue'
import type { ToolSchema } from '@/composables/useToolSettings'

const llmStore = useLlmConfigsStore()

// Tools: loaded here, provided to child pages via inject
const allTools = ref<ToolSchema[]>([])
const loadingTools = ref(false)

provide('settingsTools', { allTools, loadingTools })

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
      <SettingsSidebar :allTools="allTools" :loadingTools="loadingTools" />
      <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
