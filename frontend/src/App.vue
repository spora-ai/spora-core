<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { setupSessionHandler } from '@/api/client'
import ToastContainer from '@/components/ui/ToastContainer.vue'

const theme = useThemeStore()
const auth = useAuthStore()
const notificationStore = useNotificationStore()
const router = useRouter()
const toast = useToast()

const isHandlingSessionExpiry = ref(false)

onMounted(() => {
  theme.init()
  // Only fetch notifications if the user is already authenticated
  if (auth.user) {
    notificationStore.fetchNotifications()
  }

  setupSessionHandler(() => {
    // Prevent duplicate handling if multiple 401s fire simultaneously
    if (isHandlingSessionExpiry.value) return
    isHandlingSessionExpiry.value = true

    // Don't redirect if already on login page
    if (router.currentRoute.value.name === 'login') return

    toast.error('Your session has expired. Redirecting to login...', {
      action: 'Login now',
      onAction: () => {
        auth.logout()
        router.push({ name: 'login' })
      },
    })

    // Auto-redirect after 3 seconds if user doesn't click the action button
    setTimeout(() => {
      if (router.currentRoute.value.name !== 'login') {
        auth.logout()
        router.push({ name: 'login' })
      }
    }, 3000)
  })
})
</script>

<template>
  <RouterView />
  <ToastContainer :toasts="toast.toasts" :onDismiss="toast.dismiss" />
</template>
