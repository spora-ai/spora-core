<script setup lang="ts">
/**
 * PluginCard — single plugin summary in the inventory grid.
 * Emits `select` with the plugin resource when clicked.
 */
import { Wrench, Cpu, FileText } from 'lucide-vue-next'
import type { PluginResource } from '../types/plugin'
import Icon from '@/components/ui/Icon.vue'
import MigrationStatusBadge from './MigrationStatusBadge.vue'

defineProps<{
  plugin: PluginResource
}>()

defineEmits<{
  select: [plugin: PluginResource]
}>()
</script>

<template>
  <button
    type="button"
    class="text-left rounded-xl border border-border bg-card p-5 hover:border-primary/50 hover:shadow-sm transition-all focus:outline-none focus:ring-2 focus:ring-primary/30"
    @click="$emit('select', plugin)"
  >
    <div class="flex items-start gap-3">
      <div class="rounded-lg bg-primary/10 p-2 shrink-0">
        <Icon :name="plugin.icon" class="w-5 h-5 text-primary" />
      </div>
      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
          <h3 class="text-sm font-semibold truncate">{{ plugin.name }}</h3>
          <span
            v-if="plugin.version > 0"
            class="text-xs font-mono text-muted-foreground shrink-0"
            title="Plugin-declared schema version"
          >
            v{{ plugin.version }}
          </span>
        </div>
        <p class="text-xs text-muted-foreground mt-1 line-clamp-2 min-h-[2rem]">
          {{ plugin.description || `Plugin slug: ${plugin.slug}` }}
        </p>
      </div>
    </div>

    <div class="mt-4 flex items-center justify-between gap-2">
      <div class="flex items-center gap-3 text-xs text-muted-foreground">
        <span class="inline-flex items-center gap-1" :title="`${plugin.bundledTools.length} bundled tool(s)`">
          <Wrench class="w-3.5 h-3.5" />
          {{ plugin.bundledTools.length }}
        </span>
        <span class="inline-flex items-center gap-1" :title="`${plugin.bundledDrivers.length} bundled driver(s)`">
          <Cpu class="w-3.5 h-3.5" />
          {{ plugin.bundledDrivers.length }}
        </span>
        <span class="inline-flex items-center gap-1" :title="`${plugin.recipePaths.length} recipe path(s)`">
          <FileText class="w-3.5 h-3.5" />
          {{ plugin.recipePaths.length }}
        </span>
      </div>
      <MigrationStatusBadge
        :status="plugin.migrations.status"
        :pending="plugin.migrations.pending"
      />
    </div>
  </button>
</template>
