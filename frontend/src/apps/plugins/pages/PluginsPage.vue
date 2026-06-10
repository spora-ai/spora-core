<script setup lang="ts">
/**
 * PluginsPage — root page for the /apps/plugins route.
 *
 * Self-contained shell: GlobalNavbar + title + grid of plugin cards.
 * No sidebar, no sub-routes. Operators click a card to open the detail dialog.
 */
import { onMounted, ref, computed } from 'vue'
import { Puzzle, RefreshCw } from 'lucide-vue-next'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import { usePluginsStore } from '../stores/plugins'
import PluginCard from '../components/PluginCard.vue'
import PluginDetailDialog from '../components/PluginDetailDialog.vue'
import type { PluginResource } from '../types/plugin'

const store = usePluginsStore()
const selected = ref<PluginResource | null>(null)
const dialogOpen = ref(false)

onMounted(() => {
  store.load()
})

function openDetail(plugin: PluginResource): void {
  selected.value = plugin
  dialogOpen.value = true
}

function closeDetail(): void {
  dialogOpen.value = false
}

const hasPlugins = computed(() => store.plugins.length > 0)
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <main class="flex-1">
      <div class="max-w-6xl mx-auto px-4 py-8">
        <div class="flex items-start justify-between gap-4 mb-6">
          <div>
            <h1 class="text-2xl font-bold flex items-center gap-2">
              <Puzzle class="w-6 h-6 text-primary" />
              Plugins
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
              Installed plugins and their migration status. To add a plugin, drop it in <code class="text-xs font-mono">plugins/</code> or set <code class="text-xs font-mono">SPORA_PLUGINS_PATHS</code>.
            </p>
          </div>
          <button
            type="button"
            @click="store.load()"
            :disabled="store.loading"
            class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border bg-background text-sm hover:bg-muted transition-colors disabled:opacity-50"
          >
            <RefreshCw class="w-4 h-4" :class="store.loading ? 'animate-spin' : ''" />
            Refresh
          </button>
        </div>

        <div
          v-if="store.error"
          class="rounded-lg border border-destructive/30 bg-destructive/10 text-destructive text-sm p-4 mb-6"
        >
          {{ store.error }}
        </div>

        <div
          v-else-if="store.loading && !hasPlugins"
          class="text-sm text-muted-foreground"
        >
          Loading…
        </div>

        <div
          v-else-if="!hasPlugins"
          class="rounded-xl border border-dashed border-border bg-card p-12 text-center"
        >
          <Puzzle class="w-10 h-10 text-muted-foreground mx-auto mb-3" />
          <h2 class="text-sm font-semibold mb-1">No plugins installed</h2>
          <p class="text-xs text-muted-foreground max-w-md mx-auto">
            Plugins extend Spora with additional tools, drivers, and recipes. Once a plugin is installed it will appear here.
          </p>
        </div>

        <div
          v-else
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
        >
          <PluginCard
            v-for="plugin in store.plugins"
            :key="plugin.slug"
            :plugin="plugin"
            @select="openDetail"
          />
        </div>
      </div>
    </main>

    <PluginDetailDialog
      :open="dialogOpen"
      :plugin="selected"
      @close="closeDetail"
    />
  </div>
</template>
