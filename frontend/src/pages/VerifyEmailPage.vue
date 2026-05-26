<script setup lang="ts">
/**
 * VerifyEmailPage — handles email verification links from the backend.
 * Route: /auth/verify/:selector?token=xxx
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/ui/Icon.vue'

const route = useRoute()
const router = useRouter()

const status = ref<'loading' | 'success' | 'error'>('loading')
const errorMessage = ref('')

onMounted(async () => {
  const selector = route.params.selector as string
  const token = route.query.token as string

  if (!selector || !token) {
    status.value = 'error'
    errorMessage.value = 'Invalid verification link. Please check your email and try again.'
    return
  }

  try {
    await api.get(`/auth/verify/${encodeURIComponent(selector)}?token=${encodeURIComponent(token)}`)
    status.value = 'success'
  } catch (e) {
    status.value = 'error'
    if (e instanceof ApiError) {
      errorMessage.value = e.message
    } else {
      errorMessage.value = 'Verification failed. Please try again.'
    }
  }
})

function goToLogin(): void {
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-background px-4">
    <div class="w-full max-w-sm space-y-8 text-center">

      <!-- Loading -->
      <div v-if="status === 'loading'" class="space-y-4">
        <div class="h-12 w-12 mx-auto rounded-full bg-muted flex items-center justify-center animate-pulse">
          <Icon name="mail" class="h-6 w-6 text-muted-foreground" />
        </div>
        <p class="text-sm text-muted-foreground">Verifying your email…</p>
      </div>

      <!-- Success -->
      <div v-else-if="status === 'success'" class="space-y-6">
        <div class="h-12 w-12 mx-auto rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
          <Icon name="check" class="h-6 w-6 text-green-600 dark:text-green-400" />
        </div>
        <div class="space-y-2">
          <h1 class="text-xl font-semibold">Email verified!</h1>
          <p class="text-sm text-muted-foreground">Your account has been verified. You can now sign in.</p>
        </div>
        <button
          @click="goToLogin"
          class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-6 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors w-full"
        >
          Sign in
        </button>
      </div>

      <!-- Error -->
      <div v-else class="space-y-6">
        <div class="h-12 w-12 mx-auto rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
          <Icon name="x-circle" class="h-6 w-6 text-red-600 dark:text-red-400" />
        </div>
        <div class="space-y-2">
          <h1 class="text-xl font-semibold">Verification failed</h1>
          <p class="text-sm text-muted-foreground">{{ errorMessage }}</p>
        </div>
        <button
          @click="goToLogin"
          class="inline-flex h-10 items-center justify-center rounded-lg border border-input bg-background hover:bg-muted px-6 text-sm font-medium transition-colors w-full"
        >
          Back to sign in
        </button>
      </div>

    </div>
  </div>
</template>
