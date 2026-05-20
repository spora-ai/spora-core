<script setup lang="ts">
import { ref } from 'vue'
import { Brain } from 'lucide-vue-next'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import MemorySidebar from '@/apps/memories/components/MemorySidebar.vue'
import Icon from '@/components/ui/Icon.vue'

const sidebarOpen = ref(false)
</script>

<template>
  <div class="h-screen bg-background flex flex-col overflow-hidden">
    <GlobalNavbar />
    <div class="flex-1 flex">
      <!-- Mobile sidebar overlay -->
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 md:hidden"
        @click="sidebarOpen = false"
      >
        <div class="absolute inset-0 bg-black/50" />
        <MemorySidebar
          mobile-open
          class="absolute left-0 top-0 h-full bg-background"
          @close="sidebarOpen = false"
        />
      </div>

      <!-- Desktop sidebar -->
      <MemorySidebar class="hidden md:flex" />

      <!-- Main content -->
      <main class="flex-1 w-full overflow-y-auto">
        <!-- Mobile sidebar toggle + app header -->
        <div class="flex items-center gap-3 px-4 py-3 border-b border-border md:hidden">
          <button
            @click="sidebarOpen = true"
            class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
            title="Show memories menu"
          >
            <Icon name="menu" />
          </button>
          <div class="flex items-center gap-2">
            <Brain class="w-5 h-5 text-primary" />
            <span class="font-semibold text-sm">Memories</span>
          </div>
        </div>

        <!-- Page content -->
        <div class="px-4 py-8">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
