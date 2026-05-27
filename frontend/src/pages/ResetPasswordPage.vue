<script setup lang="ts">
/**
 * ResetPasswordPage — handles password reset links from email.
 * Route: /auth/reset-password/:selector?token=xxx
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/ui/Icon.vue'

const route = useRoute()
const router = useRouter()

const status = ref<'loading' | 'ready' | 'submitting' | 'success' | 'error'>('loading')
const errorMessage = ref('')
const password = ref('')
const confirmPassword = ref('')
const validationError = ref('')

onMounted(async () => {
  const selector = route.params.selector as string
  const token = route.query.token as string

  if (!selector || !token) {
    status.value = 'error'
    errorMessage.value = 'Invalid reset link. Please request a new password reset link.'
    return
  }

  status.value = 'ready'
})

function validate(): boolean {
  validationError.value = ''

  if (password.value.length < 8) {
    validationError.value = 'Password must be at least 8 characters.'
    return false
  }

  if (password.value !== confirmPassword.value) {
    validationError.value = 'Passwords do not match.'
    return false
  }

  return true
}

async function submit(): Promise<void> {
  if (!validate()) return

  const selector = route.params.selector as string
  const token = route.query.token as string

  status.value = 'submitting'
  validationError.value = ''

  try {
    await api.post('/auth/reset-password', {
      selector,
      token,
      password: password.value,
    })
    status.value = 'success'
  } catch (e) {
    status.value = 'ready'
    if (e instanceof ApiError) {
      errorMessage.value = e.message
    } else {
      errorMessage.value = 'Failed to reset password. Please try again.'
    }
  }
}

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
          <Icon name="lock" class="h-6 w-6 text-muted-foreground" />
        </div>
        <p class="text-sm text-muted-foreground">Preparing password reset…</p>
      </div>

      <!-- Success -->
      <div v-else-if="status === 'success'" class="space-y-6">
        <div class="h-12 w-12 mx-auto rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
          <Icon name="check" class="h-6 w-6 text-green-600 dark:text-green-400" />
        </div>
        <div class="space-y-2">
          <h1 class="text-xl font-semibold">Password reset!</h1>
          <p class="text-sm text-muted-foreground">Your password has been changed. You can now sign in.</p>
        </div>
        <button
          @click="goToLogin"
          class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-6 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors w-full"
        >
          Sign in
        </button>
      </div>

      <!-- Error -->
      <div v-else-if="status === 'error'" class="space-y-6">
        <div class="h-12 w-12 mx-auto rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
          <Icon name="x-circle" class="h-6 w-6 text-red-600 dark:text-red-400" />
        </div>
        <div class="space-y-2">
          <h1 class="text-xl font-semibold">Reset failed</h1>
          <p class="text-sm text-muted-foreground">{{ errorMessage }}</p>
        </div>
        <button
          @click="goToLogin"
          class="inline-flex h-10 items-center justify-center rounded-lg border border-input bg-background hover:bg-muted px-6 text-sm font-medium transition-colors w-full"
        >
          Back to sign in
        </button>
      </div>

      <!-- Ready / Submitting — Password Form -->
      <div v-else class="space-y-6">
        <div class="space-y-2">
          <h1 class="text-xl font-semibold">Set new password</h1>
          <p class="text-sm text-muted-foreground">Enter your new password below.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4 text-left">
          <div class="space-y-2">
            <label for="password" class="text-sm font-medium leading-none">New password</label>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
              placeholder="At least 8 characters"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <div class="space-y-2">
            <label for="confirm-password" class="text-sm font-medium leading-none">Confirm password</label>
            <input
              id="confirm-password"
              v-model="confirmPassword"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
              placeholder="Repeat your password"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <p v-if="validationError" role="alert" class="text-sm text-destructive">{{ validationError }}</p>
          <p v-if="errorMessage" role="alert" class="text-sm text-destructive">{{ errorMessage }}</p>

          <button
            type="submit"
            :disabled="status === 'submitting'"
            class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-primary px-6 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors disabled:pointer-events-none disabled:opacity-50"
          >
            {{ status === 'submitting' ? 'Resetting…' : 'Reset password' }}
          </button>
        </form>

        <p class="text-center text-sm text-muted-foreground">
          <button
            type="button"
            @click="goToLogin"
            class="font-medium text-foreground underline-offset-4 hover:underline"
          >
            Back to sign in
          </button>
        </p>
      </div>

    </div>
  </div>
</template>
