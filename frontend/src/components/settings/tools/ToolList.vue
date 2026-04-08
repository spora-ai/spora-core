<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'
import type { ToolSchema } from '@/composables/useToolSettings'

const props = defineProps<{
  tools: ToolSchema[]
  selectedToolId?: string | null
}>()

defineEmits<{ select: [toolName: string] }>()

const configurableTools = () => props.tools.filter((t) => t.settings_schema.length > 0)
</script>

<template>
  <!-- Desktop: clickable list -->
  <div class="hidden md:block rounded-xl border border-border bg-card divide-y divide-border">
    <button
      v-for="tool in configurableTools()"
      :key="tool.tool_name"
      type="button"
      @click="$emit('select', tool.tool_name)"
      class="w-full flex items-center justify-between px-5 py-4 text-left transition-colors"
      :class="selectedToolId === tool.tool_name ? 'bg-muted/70' : 'hover:bg-muted/50'"
    >
      <div>
        <p class="text-sm font-medium">{{ tool.display_name || tool.tool_name }}</p>
        <p class="text-xs text-muted-foreground mt-0.5">
          {{ tool.settings_schema.length }} setting{{ tool.settings_schema.length === 1 ? '' : 's' }}
        </p>
      </div>
      <ChevronRight class="h-4 w-4 text-muted-foreground shrink-0" />
    </button>
  </div>

  <!-- Mobile: select dropdown -->
  <div class="md:hidden">
    <label for="tool-select" class="text-sm font-medium mb-1 block">Select tool</label>
    <select
      id="tool-select"
      :value="selectedToolId ?? ''"
      @change="$emit('select', ($event.target as HTMLSelectElement).value)"
      class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
    >
      <option v-for="tool in configurableTools()" :key="tool.tool_name" :value="tool.tool_name">
        {{ tool.display_name || tool.tool_name }}
      </option>
    </select>
  </div>
</template>
