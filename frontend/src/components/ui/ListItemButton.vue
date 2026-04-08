<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'

defineProps<{
  title: string
  subtitle?: string
  active?: boolean
}>()

defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    @click="$emit('click')"
    class="w-full flex items-center justify-between px-5 py-4 text-left transition-colors"
    :class="active ? 'bg-muted/70' : 'hover:bg-muted/50'"
  >
    <div>
      <p class="text-sm font-medium">{{ title }}</p>
      <p v-if="subtitle" class="text-xs text-muted-foreground mt-0.5">{{ subtitle }}</p>
    </div>
    <ChevronRight class="h-4 w-4 text-muted-foreground shrink-0" />
  </button>
</template>
