<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useNotificationStore } from '@/stores/notifications'
import { useRealtime } from '@/composables/useRealtime'
import NotificationCenter from './NotificationCenter.vue'
import Icon from '@/components/ui/Icon.vue'
import LogoSvg from '@/assets/logo.svg?asset'

const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const notificationStore = useNotificationStore()

// Initialize real-time connection (auto-cleans up on unmount)
useRealtime()

const notificationCenter = ref<InstanceType<typeof NotificationCenter> | null>(null)
const userMenuOpen = ref(false)

async function logout(): Promise<void> {
  userMenuOpen.value = false
  await auth.logout()
  router.push({ name: 'login' })
}

function openNotifications() {
  notificationCenter.value?.open()
}

function closeUserMenu(): void {
  userMenuOpen.value = false
}
</script>

<template>
  <header class="h-14 border-b border-border bg-background flex items-center px-4 gap-4 shrink-0">
    <!-- Logo / App name -->
    <RouterLink
      to="/"
      class="flex items-center gap-2 font-semibold tracking-tight text-foreground hover:opacity-80 transition-opacity"
    >
      <img :src="LogoSvg" alt="Spora" class="h-8 w-auto dark:invert" />
    </RouterLink>

    <div class="flex-1" />

    <!-- Settings -->
    <RouterLink
      to="/settings"
      class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      title="Settings"
    >
      <Icon name="settings" />
    </RouterLink>

    <!-- Bell / notification icon -->
    <button
      @click="openNotifications"
      class="relative flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      title="Notifications"
    >
      <Icon name="bell" />
      <span
        v-if="notificationStore.unreadCount > 0"
        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-primary text-primary-foreground text-[10px] font-bold px-1"
      >
        {{ notificationStore.unreadCount > 99 ? '99+' : notificationStore.unreadCount }}
      </span>
    </button>

    <!-- Dark mode toggle -->
    <button
      @click="theme.toggle()"
      class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      :title="theme.isDark ? 'Switch to light mode' : 'Switch to dark mode'"
    >
      <Icon v-if="theme.isDark" name="sun" />
      <Icon v-else name="moon" />
    </button>

    <!-- User menu -->
    <button
      @click="userMenuOpen = !userMenuOpen"
      class="flex items-center justify-center h-8 w-8 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
      title="Account menu"
    >
      <Icon name="user" />
    </button>

    <!-- User dropdown -->
    <Teleport to="body">
      <div
        v-if="userMenuOpen"
        class="fixed inset-0 z-50"
        @click="closeUserMenu"
      >
        <div class="absolute right-4 top-14 w-48 rounded-lg border border-border bg-background shadow-md overflow-hidden">
          <nav class="py-1">
            <button
              @click="() => { closeUserMenu(); router.push({ name: 'account' }) }"
              class="w-full flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted transition-colors"
            >
              <Icon name="user" class="h-4 w-4 text-muted-foreground" />
              My Account
            </button>
            <button
              @click="() => { closeUserMenu(); router.push({ name: 'profile' }) }"
              class="w-full flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted transition-colors"
            >
              <Icon name="user" class="h-4 w-4 text-muted-foreground" />
              Profile
            </button>
            <hr class="my-1 border-border" />
            <button
              @click="logout"
              class="w-full flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
            >
              <Icon name="logout" />
              Sign out
            </button>
          </nav>
        </div>
      </div>
    </Teleport>

    <!-- Notification center panel -->
    <NotificationCenter ref="notificationCenter" />
  </header>
</template>
