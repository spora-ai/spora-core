<script setup lang="ts">
/**
 * LLMConfigsPage — legacy redirect.
 * All LLM configuration functionality lives in /settings/llm.
 */
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

onMounted(() => {
  router.replace({ name: 'settings-llm' })
})
</script>

<template>
  <div class="min-h-screen bg-background flex items-center justify-center">
    <p class="text-sm text-muted-foreground">Redirecting…</p>
  </div>
</template>
