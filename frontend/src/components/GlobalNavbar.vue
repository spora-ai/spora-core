<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()

async function logout(): Promise<void> {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="h-14 border-b border-border bg-background flex items-center px-4 gap-4 shrink-0">
    <!-- Logo / App name -->
    <RouterLink
      to="/"
      class="flex items-center gap-2 font-semibold tracking-tight text-foreground hover:opacity-80 transition-opacity"
    >
      <div class="h-7 w-7 rounded-lg bg-primary flex items-center justify-center">
        <span class="text-primary-foreground text-xs font-bold">S</span>
      </div>
      <span>Spora</span>
    </RouterLink>

    <div class="flex-1" />

    <!-- Dark mode toggle -->
    <button
      @click="theme.toggle()"
      class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      :title="theme.isDark ? 'Switch to light mode' : 'Switch to dark mode'"
    >
      <svg v-if="theme.isDark" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
      <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
      </svg>
    </button>

    <!-- User menu -->
    <div class="flex items-center gap-3">
      <span class="text-sm text-muted-foreground hidden sm:block">{{ auth.user?.email }}</span>
      <button
        @click="logout"
        class="text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        Sign out
      </button>
    </div>
  </header>
</template>
