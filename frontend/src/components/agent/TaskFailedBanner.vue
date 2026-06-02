<script setup lang="ts">
/**
 * TaskFailedBanner — small pill shown at the bottom of a failed task's
 * conversation. Extracted from TaskChatPage.vue to keep the page focused on
 * orchestration.
 */
defineProps<{
  stepCount: number
}>()
</script>

<template>
  <div class="flex justify-center">
    <div class="rounded-full bg-red-100 dark:bg-red-900/30 px-4 py-1.5 text-xs text-red-700 dark:text-red-300">
      Task failed after {{ stepCount }} step{{ stepCount !== 1 ? 's' : '' }}.
    </div>
  </div>
</template>
