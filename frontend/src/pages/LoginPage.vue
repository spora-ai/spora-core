<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isRegistrationEnabled } from '@/utils/auth'
import LogoSvg from '@/assets/logo.svg?asset'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)
const registrationEnabled = ref(true)

onMounted(async () => {
  registrationEnabled.value = await isRegistrationEnabled()
})

async function submit(): Promise<void> {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e: any) {
    error.value = e?.name === 'ApiError' ? e.message : 'An unexpected error occurred.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-background px-4">
    <div class="w-full max-w-sm space-y-8">

      <div class="text-center space-y-6">
        <img :src="LogoSvg" alt="Spora" class="h-12 mx-auto dark:invert" />
        <p class="text-sm text-muted-foreground">Sign in to your account</p>
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
          <label for="password" class="text-sm font-medium leading-none">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
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
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </button>

        <p class="text-center text-sm">
          <RouterLink to="/forgot-password" class="font-medium text-foreground underline-offset-4 hover:underline">
            Forgot your password?
          </RouterLink>
        </p>
      </form>

      <p v-if="registrationEnabled" class="text-center text-sm text-muted-foreground">
        Don't have an account?
        <RouterLink to="/register" class="font-medium text-foreground underline-offset-4 hover:underline">
          Register
        </RouterLink>
      </p>

    </div>
  </div>
</template>
