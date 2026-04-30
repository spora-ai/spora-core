<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import type { LLMConfigResource } from '@/types/llmConfig'

defineEmits<{
  select: [config: LLMConfigResource]
  create: []
}>()

const llmStore = useLlmConfigsStore()
</script>

<template>
  <!-- Empty state -->
  <div v-if="llmStore.configs.length === 0" class="rounded-xl border border-border bg-card p-8 text-center">
    <p class="text-sm text-muted-foreground mb-4">No LLM configurations yet.</p>
    <button
      @click="$emit('create')"
      class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
    >
      Create your first configuration
    </button>
  </div>

  <!-- List -->
  <template v-else>
    <div class="rounded-xl border border-border bg-card divide-y divide-border">
      <button
        v-for="config in llmStore.configs"
        :key="config.id"
        type="button"
        @click="$emit('select', config)"
        class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-muted/50 transition-colors"
      >
        <div>
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ config.name }}</span>
            <span
              v-if="config.is_global && config.is_default"
              class="text-xs rounded-full bg-accent/10 text-accent px-1.5 py-0.5 font-medium"
            >
              Global Default
            </span>
            <span
              v-else-if="config.is_global"
              class="text-xs rounded-full bg-muted text-muted-foreground px-1.5 py-0.5 font-medium"
            >
              Global
            </span>
            <span
              v-else-if="config.is_default"
              class="text-xs rounded-full bg-primary/10 text-primary px-1.5 py-0.5 font-medium"
            >
              Default
            </span>
          </div>
          <p class="text-xs text-muted-foreground mt-0.5">{{ config.driver_display_name }}</p>
        </div>
        <ChevronRight class="h-4 w-4 text-muted-foreground shrink-0" />
      </button>
    </div>
    <div class="mt-4 flex justify-end">
      <button
        @click="$emit('create')"
        class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
      >
        + Add New
      </button>
    </div>
  </template>
</template>
