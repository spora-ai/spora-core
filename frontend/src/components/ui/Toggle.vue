<script setup lang="ts">
defineProps<{
  modelValue: boolean
  disabled?: boolean
  size?: 'sm' | 'md'
}>()

defineEmits<{
  'update:modelValue': [value: boolean]
}>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :disabled="disabled"
    @click="!disabled && $emit('update:modelValue', !modelValue)"
    class="relative inline-flex shrink-0 rounded-full transition-colors focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 disabled:cursor-not-allowed"
    :class="[
      size === 'sm' ? 'h-5 w-9' : 'h-6 w-11',
      modelValue ? 'bg-[hsl(var(--toggle-active))]' : 'bg-muted'
    ]"
  >
    <span
      class="inline-block rounded-full bg-white shadow-sm transition-transform"
      :class="[
        size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4',
        size === 'sm'
          ? (modelValue ? 'translate-x-4' : 'translate-x-0.5')
          : (modelValue ? 'translate-x-6' : 'translate-x-1')
      ]"
    />
  </button>
</template>
