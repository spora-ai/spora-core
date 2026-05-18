<script setup lang="ts">
import { Brain, ChevronRight, GripVertical } from 'lucide-vue-next'
import type { MemoryResource } from '../types/memory'

withDefaults(defineProps<{
  memory: MemoryResource
  showHandle?: boolean
}>(), {
  showHandle: false,
})

defineEmits<{
  select: [memory: MemoryResource]
}>()
</script>

<template>
  <div
    class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-muted/50 transition-colors rounded-lg group"
    @click="$emit('select', memory)"
  >
    <div class="flex items-center gap-3 min-w-0">
      <GripVertical v-if="showHandle" class="w-4 h-4 text-muted-foreground flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" />
      <Brain class="w-4 h-4 text-primary flex-shrink-0" />
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">{{ memory.name }}</span>
          <span
            v-if="memory.order !== 0"
            class="text-xs rounded-full bg-muted text-muted-foreground px-1.5 py-0.5"
          >
            #{{ memory.order }}
          </span>
        </div>
        <p v-if="memory.summary" class="text-xs text-muted-foreground truncate mt-0.5">
          {{ memory.summary }}
        </p>
      </div>
    </div>
    <ChevronRight class="w-4 h-4 text-muted-foreground flex-shrink-0" />
  </div>
</template>
