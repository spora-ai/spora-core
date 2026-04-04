<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

async function logout(): Promise<void> {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-background">
    <header class="border-b border-border px-6 py-4 flex items-center justify-between">
      <span class="font-semibold">Spora</span>
      <div class="flex items-center gap-4">
        <span class="text-sm text-muted-foreground">{{ auth.user?.email }}</span>
        <button
          @click="logout"
          class="text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          Sign out
        </button>
      </div>
    </header>

    <main class="flex items-center justify-center h-[calc(100vh-57px)]">
      <p class="text-muted-foreground text-sm">Dashboard — coming soon</p>
    </main>
  </div>
</template>
