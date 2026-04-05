<script setup lang="ts">
/**
 * LLMConfigsPage — /settings/llm (legacy)
 *
 * This page is now a passthrough. All LLM configuration functionality
 * lives in GlobalSettingsPage (/settings?section=llm).
 */
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

onMounted(() => {
  router.replace({ name: 'settings', query: { section: 'llm' } })
})
</script>

<template>
  <div class="min-h-screen bg-background flex items-center justify-center">
    <p class="text-sm text-muted-foreground">Redirecting…</p>
  </div>
</template>