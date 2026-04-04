<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'

const theme = useThemeStore()

onMounted(() => {
  theme.init()
})
</script>

<template>
  <RouterView />
</template>
