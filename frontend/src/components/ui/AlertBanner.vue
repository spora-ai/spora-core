<script setup lang="ts">
defineProps<{
  type: 'success' | 'error'
  message: string
}>()
</script>

<template>
  <div
    role="alert"
    class="rounded-lg px-4 py-3 text-sm"
    :class="
      type === 'success'
        ? 'bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300'
        : 'border border-destructive/30 bg-destructive/10 text-destructive'
    "
  >
    {{ message }}
  </div>
</template>
