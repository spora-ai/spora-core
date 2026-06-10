<script setup lang="ts">
/**
 * PluginDetailDialog — full metadata for a single plugin.
 *
 * Read-only: shows the same fields as the card plus the absolute plugin path,
 * recipe paths, and the breakdown of the migration status.
 */
import { X, Wrench, Cpu, FileText, FolderOpen, Hash } from 'lucide-vue-next'
import type { PluginResource } from '../types/plugin'
import MigrationStatusBadge from './MigrationStatusBadge.vue'

defineProps<{
  open: boolean
  plugin: PluginResource | null
}>()

const emit = defineEmits<{
  close: []
}>()

function close(): void {
  emit('close')
}

function formatDate(iso: string | null): string {
  if (iso === null) return 'never'
  // The backend stores updated_at as Y-m-d H:i:s (server timezone). Surface it raw.
  return iso
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && plugin"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      @click.self="close"
    >
      <div class="absolute inset-0 bg-black/50" @click="close" />
      <div
        data-testid="plugin-detail-dialog"
        class="relative z-10 w-full max-w-2xl max-h-[90vh] flex flex-col rounded-xl border border-border bg-card shadow-lg"
      >
        <header class="flex items-start justify-between gap-3 p-5 border-b border-border">
          <div>
            <h2 class="text-lg font-semibold">{{ plugin.name }}</h2>
            <p class="text-xs text-muted-foreground mt-0.5 font-mono">{{ plugin.slug }}</p>
          </div>
          <button
            type="button"
            @click="close"
            class="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
            aria-label="Close dialog"
          >
            <X class="w-5 h-5" />
          </button>
        </header>

        <div class="flex-1 overflow-y-auto p-5 space-y-5">
          <p v-if="plugin.description" class="text-sm text-foreground/80">
            {{ plugin.description }}
          </p>

          <section v-if="plugin.bundledTools.length > 0">
            <h3 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              <Wrench class="w-3.5 h-3.5" />
              Bundled tools ({{ plugin.bundledTools.length }})
            </h3>
            <ul class="space-y-1.5">
              <li
                v-for="tool in plugin.bundledTools"
                :key="tool.name"
                class="rounded-lg border border-border bg-background p-3"
              >
                <div class="flex items-center gap-2">
                  <Hash class="w-3 h-3 text-muted-foreground" />
                  <code class="text-xs font-mono">{{ plugin.slug }}:{{ tool.name }}</code>
                </div>
                <p class="text-xs text-muted-foreground mt-1">{{ tool.description }}</p>
              </li>
            </ul>
          </section>

          <section v-if="plugin.bundledDrivers.length > 0">
            <h3 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              <Cpu class="w-3.5 h-3.5" />
              Bundled drivers ({{ plugin.bundledDrivers.length }})
            </h3>
            <ul class="space-y-1.5">
              <li
                v-for="driver in plugin.bundledDrivers"
                :key="driver.provider"
                class="rounded-lg border border-border bg-background p-3 text-xs"
              >
                <div class="font-medium">{{ driver.provider }}</div>
                <code class="text-muted-foreground">{{ driver.class }}</code>
              </li>
            </ul>
          </section>

          <section v-if="plugin.recipePaths.length > 0">
            <h3 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              <FileText class="w-3.5 h-3.5" />
              Recipe paths
            </h3>
            <ul class="space-y-1 text-xs font-mono">
              <li v-for="path in plugin.recipePaths" :key="path" class="rounded border border-border bg-background px-2 py-1.5">
                {{ path }}
              </li>
            </ul>
          </section>

          <section>
            <h3 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              Migrations
            </h3>
            <div class="rounded-lg border border-border bg-background p-3 space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-xs text-muted-foreground">Status</span>
                <MigrationStatusBadge :status="plugin.migrations.status" :pending="plugin.migrations.pending" />
              </div>
              <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                <dt class="text-muted-foreground">Declared version</dt>
                <dd class="font-mono text-right">{{ plugin.migrations.declared }}</dd>
                <dt class="text-muted-foreground">Files on disk</dt>
                <dd class="font-mono text-right">{{ plugin.migrations.filesOnDisk }}</dd>
                <dt class="text-muted-foreground">Applied</dt>
                <dd class="font-mono text-right">{{ plugin.migrations.applied }}</dd>
                <dt class="text-muted-foreground">Pending</dt>
                <dd class="font-mono text-right">{{ plugin.migrations.pending }}</dd>
                <dt class="text-muted-foreground">Last applied</dt>
                <dd class="text-right">{{ formatDate(plugin.migrations.lastAppliedAt) }}</dd>
              </dl>
            </div>
          </section>

          <section v-if="plugin.path">
            <h3 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              <FolderOpen class="w-3.5 h-3.5" />
              Plugin path
            </h3>
            <code class="block text-xs font-mono rounded-lg border border-border bg-background px-3 py-2 break-all">
              {{ plugin.path }}
            </code>
          </section>
        </div>
      </div>
    </div>
  </Teleport>
</template>
