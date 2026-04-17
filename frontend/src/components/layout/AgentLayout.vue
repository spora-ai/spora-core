<script setup lang="ts">
/**
 * AgentLayout — shared layout shell for agent sub-pages.
 * Wraps: GlobalNavbar + AgentSidebar + AgentHeaderToolbar + page content slot.
 */
import { ref } from 'vue'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import AgentSidebar from '@/components/layout/AgentSidebar.vue'
import AgentHeaderToolbar from '@/components/layout/AgentHeaderToolbar.vue'

const props = withDefaults(defineProps<{
  agentId: number
  llmUnconfigured?: boolean
  showToolbar?: boolean
}>(), {
  showToolbar: true,
})

const sidebarOpen = ref(false)

function openSidebar(): void {
  sidebarOpen.value = true
}

function closeSidebar(): void {
  sidebarOpen.value = false
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">

    <GlobalNavbar />

    <div class="flex flex-1 overflow-hidden">

      <!-- Mobile sidebar overlay -->
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 lg:hidden"
        @click="closeSidebar"
      >
        <div class="absolute inset-0 bg-black/50" />
        <AgentSidebar :agent-id="agentId" mobile-open @close="closeSidebar" />
      </div>

      <!-- Desktop sidebar -->
      <AgentSidebar :agent-id="agentId" class="hidden lg:flex" />

      <!-- Main column -->
      <div class="flex-1 flex flex-col min-w-0">

        <!-- Agent header toolbar (with sidebar toggle) -->
        <AgentHeaderToolbar
          v-if="props.showToolbar"
          :agent-id="agentId"
          :llm-unconfigured="llmUnconfigured ?? false"
          @open-sidebar="openSidebar"
        />

        <!-- Page-specific content -->
        <div class="flex-1 overflow-y-auto">
          <slot />
        </div>

      </div>
    </div>
  </div>
</template>
