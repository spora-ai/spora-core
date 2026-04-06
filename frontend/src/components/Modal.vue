<script setup lang="ts">
/**
 * Modal — reusable modal dialog component.
 *
 * Uses Teleport to render at <body> level to avoid stacking context issues.
 * Backdrop color is applied directly to the fixed container (following DashboardPage pattern).
 *
 * Props:
 *   modelValue  — v-model boolean to show/hide
 *   title       — optional title string
 *   size        — 'sm' | 'md' | 'lg', defaults to 'md'
 *   backdropClosable — close on backdrop click, defaults to true
 *
 * Emits:
 *   update:modelValue
 *   close
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg'
  backdropClosable?: boolean
}>(), {
  size: 'md',
  backdropClosable: true,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'close': []
}>()

const sizeClass = computed(() => ({
  sm: 'max-w-md',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
}[props.size]))

function close(): void {
  emit('update:modelValue', false)
  emit('close')
}

function onBackdropClick(): void {
  if (props.backdropClosable) {
    close()
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="onBackdropClick"
    >
      <div
        class="bg-background rounded-xl border border-border shadow-2xl w-full mx-4 flex flex-col max-h-[85vh]"
        :class="sizeClass"
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-border shrink-0">
          <slot name="header">
            <h2 v-if="title" class="text-base font-semibold">{{ title }}</h2>
          </slot>
          <button
            @click="close"
            class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto px-5 py-4">
          <slot />
        </div>

        <!-- Footer -->
        <div v-if="$slots.footer" class="px-5 py-4 border-t border-border shrink-0">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
