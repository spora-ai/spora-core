<script setup lang="ts">
/**
 * SettingsNavGroup — collapsible section wrapper for the Settings sidebar.
 *
 * Each group renders a title (when provided) and a slot for the actual nav
 * items. The collapse state is local; the page is a thin shell that maps
 * over groups.
 */
import { ref } from 'vue'
import { ChevronRight } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  title?: string
  defaultOpen?: boolean
}>(), {
  title: '',
  defaultOpen: true,
})

const isOpen = ref(props.defaultOpen)

function toggle(): void {
  isOpen.value = !isOpen.value
}
</script>

<template>
  <div>
    <button
      v-if="title"
      type="button"
      class="w-full flex items-center justify-between text-sm font-semibold uppercase tracking-wider mb-3 text-muted-foreground"
      :aria-expanded="isOpen"
      @click="toggle"
    >
      <span>{{ title }}</span>
      <ChevronRight
        class="h-3.5 w-3.5 transition-transform"
        :class="isOpen ? 'rotate-90' : ''"
      />
    </button>
    <div v-show="isOpen">
      <slot />
    </div>
  </div>
</template>
