<script setup lang="ts">
withDefaults(defineProps<{
  modelValue: boolean
  disabled?: boolean
  size?: 'sm' | 'md'
  /** Additional classes for the on (active) state track */
  activeClass?: string
  /** Additional classes for the off (inactive) state track */
  inactiveClass?: string
}>(), {
  activeClass: '',
  inactiveClass: '',
})

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
    class="relative inline-flex shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
    :class="[
      size === 'sm' ? 'h-5 w-9' : 'h-6 w-11',
      modelValue ? ['bg-primary', activeClass] : ['bg-input', inactiveClass]
    ]"
  >
    <span
      class="pointer-events-none inline-block rounded-full bg-background shadow-lg ring-0 transition-transform"
      :class="[
        size === 'sm' ? 'h-4 w-4' : 'h-5 w-5',
        size === 'sm'
          ? (modelValue ? 'translate-x-4' : 'translate-x-0')
          : (modelValue ? 'translate-x-5' : 'translate-x-0')
      ]"
    />
  </button>
</template>
