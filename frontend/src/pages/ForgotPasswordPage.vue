<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const error = ref<string | null>(null)
const loading = ref(false)
const success = ref(false)

async function submit(): Promise<void> {
  error.value = null
  loading.value = true
  try {
    await auth.forgotPassword(email.value)
    success.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'An unexpected error occurred.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-background px-4">
    <div class="w-full max-w-sm space-y-8">

      <template v-if="!success">
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
          <p class="text-sm text-muted-foreground">
            Enter your email address and we'll send you a link to reset your password.
          </p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
          <div class="space-y-2">
            <label for="email" class="text-sm font-medium leading-none">Email</label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="email"
              required
              placeholder="you@example.com"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ loading ? 'Sending…' : 'Send reset link' }}
          </button>
        </form>
      </template>

      <template v-else>
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Check your email</h1>
          <p class="text-sm text-muted-foreground">
            If an account with <strong>{{ email }}</strong> exists, we've sent a password reset link.
          </p>
        </div>

        <p class="text-center text-sm text-muted-foreground">
          <button
            type="button"
            @click="router.push({ name: 'login' })"
            class="font-medium text-foreground underline-offset-4 hover:underline"
          >
            Back to sign in
          </button>
        </p>
      </template>

    </div>
  </div>
</template>
