<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useNotificationStore } from '@/stores/notifications'

const theme = useThemeStore()
const notificationStore = useNotificationStore()

onMounted(() => {
  theme.init()
  notificationStore.fetchNotifications()
})
</script>

<template>
  <RouterView />
</template>
