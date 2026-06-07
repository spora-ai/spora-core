<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'

const auth = useAuthStore()

const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const displayName = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

// Verification state
const pending = ref(false)
const resendLoading = ref(false)
const resendError = ref<string | null>(null)
const resendSuccess = ref(false)

async function submit(): Promise<void> {
  if (password.value !== confirmPassword.value) {
    error.value = 'Passwords do not match.'
    return
  }
  error.value = null
  loading.value = true
  try {
    await auth.register(email.value, password.value, confirmPassword.value, displayName.value)
    pending.value = true
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'An unexpected error occurred.'
  } finally {
    loading.value = false
  }
}

async function resendVerification(): Promise<void> {
  resendError.value = null
  resendLoading.value = true
  try {
    await auth.resendVerification(email.value)
    resendSuccess.value = true
  } catch (e) {
    resendError.value = e instanceof ApiError ? e.message : 'Failed to resend verification email.'
  } finally {
    resendLoading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-background px-4">
    <div class="w-full max-w-sm space-y-8">

      <!-- Registration form -->
      <template v-if="!pending">
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Spora</h1>
          <p class="text-sm text-muted-foreground">Create an account</p>
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

          <div class="space-y-2">
            <label for="displayName" class="text-sm font-medium leading-none">Display Name</label>
            <input
              id="displayName"
              v-model="displayName"
              type="text"
              autocomplete="name"
              required
              placeholder="Jane Doe"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <div class="space-y-2">
            <label for="password" class="text-sm font-medium leading-none">Password</label>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              required
              placeholder="••••••••"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <div class="space-y-2">
            <label for="confirmPassword" class="text-sm font-medium leading-none">Confirm Password</label>
            <input
              id="confirmPassword"
              v-model="confirmPassword"
              type="password"
              autocomplete="new-password"
              required
              placeholder="••••••••"
              class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
          </div>

          <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>

          <button
            type="submit"
            :disabled="loading"
            class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ loading ? 'Creating account…' : 'Create account' }}
          </button>
        </form>

        <p class="text-center text-sm text-muted-foreground">
          Already have an account?
          <RouterLink to="/login" class="font-medium text-foreground underline-offset-4 hover:underline">
            Sign in
          </RouterLink>
        </p>
      </template>

      <!-- Email verification pending -->
      <template v-else>
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Check your email</h1>
          <p class="text-sm text-muted-foreground">
            We've sent a verification link to <strong>{{ email }}</strong>.
          </p>
        </div>

        <div class="space-y-4">
          <p class="text-center text-sm text-muted-foreground">
            Click the link in your email to activate your account. If you don't see it, check your spam folder.
          </p>

          <p v-if="resendError" role="alert" class="text-sm text-destructive">{{ resendError }}</p>
          <output v-if="resendSuccess" class="text-sm text-green-600 dark:text-green-400">
            Verification email resent!
          </output>

          <button
            type="button"
            :disabled="resendLoading || resendSuccess"
            @click="resendVerification"
            class="inline-flex h-9 w-full items-center justify-center rounded-md border border-input bg-transparent px-4 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50"
          >
            {{ resendLoading ? 'Resending…' : resendSuccess ? 'Email resent' : 'Resend verification email' }}
          </button>

          <p class="text-center text-sm text-muted-foreground">
            <button
              type="button"
              @click="pending = false"
              class="font-medium text-foreground underline-offset-4 hover:underline"
            >
              Use a different email address
            </button>
          </p>
        </div>
      </template>

    </div>
  </div>
</template>
