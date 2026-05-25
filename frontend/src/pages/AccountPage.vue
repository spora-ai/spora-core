<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import GlobalNavbar from '@/components/GlobalNavbar.vue'

const auth = useAuthStore()

// ── Username form ────────────────────────────────────────────────────────────

const username = ref(auth.user?.username ?? '')
const usernameSaving = ref(false)
const usernameError = ref<string | null>(null)
const usernameSuccess = ref(false)

async function saveUsername(): Promise<void> {
  const val = username.value.trim()
  if (!val) return
  usernameSaving.value = true
  usernameError.value = null
  usernameSuccess.value = false
  try {
    await auth.updateAccount(val)
    usernameSuccess.value = true
    setTimeout(() => { usernameSuccess.value = false }, 3000)
  } catch (e: any) {
    usernameError.value = e?.name === 'ApiError' ? e.message : 'Failed to update display name.'
  } finally {
    usernameSaving.value = false
  }
}

// ── Email change form ─────────────────────────────────────────────────────────

const newEmail = ref('')
const emailPasswordConfirm = ref('')
const emailSaving = ref(false)
const emailError = ref<string | null>(null)
const emailSuccess = ref(false)

async function saveEmail(): Promise<void> {
  if (!newEmail.value || !emailPasswordConfirm.value) return
  emailSaving.value = true
  emailError.value = null
  emailSuccess.value = false
  try {
    await auth.changeEmail(newEmail.value)
    emailSuccess.value = true
    newEmail.value = ''
    emailPasswordConfirm.value = ''
    setTimeout(() => { emailSuccess.value = false }, 5000)
  } catch (e: any) {
    emailError.value = e?.name === 'ApiError' ? e.message : 'Failed to request email change.'
  } finally {
    emailSaving.value = false
  }
}

// ── Password form ───────────────────────────────────────────────────────────

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordSaving = ref(false)
const passwordError = ref<string | null>(null)

const passwordsMatch = computed(() => newPassword.value === confirmPassword.value)

async function savePassword(): Promise<void> {
  if (!currentPassword.value || !newPassword.value) return
  if (!passwordsMatch.value) {
    passwordError.value = 'New passwords do not match.'
    return
  }
  if (newPassword.value.length < 8) {
    passwordError.value = 'New password must be at least 8 characters.'
    return
  }
  passwordSaving.value = true
  passwordError.value = null
  try {
    await auth.changePassword(currentPassword.value, newPassword.value)
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
  } catch (e: any) {
    passwordError.value = e?.name === 'ApiError' ? e.message : 'Failed to change password.'
  } finally {
    passwordSaving.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <!-- Header -->
    <div class="px-6 py-4 border-b border-border">
      <h1 class="text-lg font-semibold">My Account</h1>
    </div>

    <main class="flex-1 flex items-start justify-center px-4 py-8">
      <div class="w-full max-w-sm space-y-8">

        <!-- Display name -->
        <section class="space-y-4">
          <h2 class="text-sm font-semibold text-foreground">Display Name</h2>
          <div class="flex gap-2">
            <div class="flex-1 space-y-1">
              <input
                v-model="username"
                type="text"
                placeholder="Your display name"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
              <p class="text-xs text-muted-foreground">{{ auth.user?.email }}</p>
            </div>
            <button
              @click="saveUsername"
              :disabled="usernameSaving || !username.trim()"
              class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ usernameSaving ? 'Saving…' : 'Save' }}
            </button>
          </div>
          <p v-if="usernameError" role="alert" class="text-xs text-destructive">{{ usernameError }}</p>
          <p v-if="usernameSuccess" role="status" class="text-xs text-green-600">Display name updated.</p>
        </section>

        <!-- Change email -->
        <section class="space-y-4">
          <h2 class="text-sm font-semibold text-foreground">Change Email Address</h2>
          <p class="text-xs text-muted-foreground">
            We'll send a confirmation link to your new email address.
          </p>
          <form @submit.prevent="saveEmail" class="space-y-3">
            <div class="space-y-2">
              <label for="new-email" class="text-sm font-medium">New Email Address</label>
              <input
                id="new-email"
                v-model="newEmail"
                type="email"
                autocomplete="email"
                placeholder="new@example.com"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
            <div class="space-y-2">
              <label for="email-password" class="text-sm font-medium">Confirm with Password</label>
              <input
                id="email-password"
                v-model="emailPasswordConfirm"
                type="password"
                autocomplete="current-password"
                placeholder="Enter your current password"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
            <p v-if="emailError" role="alert" class="text-xs text-destructive">{{ emailError }}</p>
            <p v-if="emailSuccess" role="status" class="text-xs text-green-600">
              Confirmation email sent. Please check your new email inbox.
            </p>
            <button
              type="submit"
              :disabled="emailSaving || !newEmail || !emailPasswordConfirm"
              class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ emailSaving ? 'Sending…' : 'Send Confirmation Email' }}
            </button>
          </form>
        </section>

        <!-- Change password -->
        <section class="space-y-4">
          <h2 class="text-sm font-semibold text-foreground">Change Password</h2>
          <form @submit.prevent="savePassword" class="space-y-3">
            <div class="space-y-2">
              <label for="current-pw" class="text-sm font-medium">Current Password</label>
              <input
                id="current-pw"
                v-model="currentPassword"
                type="password"
                autocomplete="current-password"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
            <div class="space-y-2">
              <label for="new-pw" class="text-sm font-medium">New Password</label>
              <input
                id="new-pw"
                v-model="newPassword"
                type="password"
                autocomplete="new-password"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
            <div class="space-y-2">
              <label for="confirm-pw" class="text-sm font-medium">Confirm New Password</label>
              <input
                id="confirm-pw"
                v-model="confirmPassword"
                type="password"
                autocomplete="new-password"
                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
            <p v-if="passwordError" role="alert" class="text-xs text-destructive">{{ passwordError }}</p>
            <button
              type="submit"
              :disabled="passwordSaving || !currentPassword || !newPassword || !confirmPassword"
              class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ passwordSaving ? 'Updating…' : 'Update Password' }}
            </button>
          </form>
        </section>

      </div>
    </main>
  </div>
</template>