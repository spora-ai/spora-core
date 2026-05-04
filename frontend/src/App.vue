<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'

const theme = useThemeStore()
const auth = useAuthStore()
const notificationStore = useNotificationStore()

onMounted(() => {
  theme.init()
  // Only fetch notifications if the user is already authenticated
  if (auth.user) {
    notificationStore.fetchNotifications()
  }
})
</script>

<template>
  <RouterView />
</template>
