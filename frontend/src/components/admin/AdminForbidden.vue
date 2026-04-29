<script setup lang="ts">
defineProps<{
  message?: string
}>()
</script>
<template>
  <main class="flex-1 flex flex-col items-center justify-center text-center px-4">
    <Icon name="warning" class="h-12 w-12 text-muted-foreground mb-4" />
    <h1 class="text-lg font-semibold">Forbidden</h1>
    <p class="text-sm text-muted-foreground mt-1">{{ message ?? 'You need admin privileges to access this page.' }}</p>
  </main>
</template>