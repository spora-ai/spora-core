<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useMailConfigStore } from '@/stores/mailConfig'
import { useToast } from '@/composables/useToast'
import GlobalNavbar from '@/components/GlobalNavbar.vue'

const auth = useAuthStore()
const mailConfig = useMailConfigStore()
const toast = useToast()

const cfg = computed(() => mailConfig.config!)
const isSmtp = computed(() => cfg.value?.driver === 'smtp')

const encryptionOptions = [
  { label: 'TLS', value: 'tls' },
  { label: 'SSL', value: 'ssl' },
  { label: 'None', value: '' },
]

onMounted(async () => {
  try {
    await mailConfig.fetchConfig()
  } catch {
    toast.error('Failed to load mail configuration.')
  }
})

async function save(): Promise<void> {
  if (!cfg.value) return
  try {
    await mailConfig.saveConfig({
      driver: cfg.value.driver,
      host: cfg.value.host || '',
      port: cfg.value.port,
      username: cfg.value.username,
      password: cfg.value.password || null,
      from_address: cfg.value.from_address,
      from_name: cfg.value.from_name,
      encryption: cfg.value.encryption,
    })
    toast.success('Mail configuration saved.')
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to save mail configuration.')
  }
}

async function testConnection(): Promise<void> {
  try {
    await mailConfig.testConnection()
    toast.success('Test email sent. Check your inbox.')
  } catch (e) {
    toast.error(e instanceof Error ? e.message : 'Failed to send test email.')
  }
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />

    <main class="flex-1 px-4 py-8">
      <div class="max-w-2xl mx-auto">

        <!-- Mobile back -->
        <div class="md:hidden mb-6">
          <button
            @click="$router.push({ name: 'settings-overview' })"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium"
          >
            ← Overview
          </button>
        </div>

        <!-- Header -->
        <div class="mb-6">
          <h1 class="text-lg font-semibold">Mail Settings</h1>
          <p class="text-sm text-muted-foreground mt-0.5">
            Configure how Spora sends emails.
          </p>
        </div>

        <!-- Loading state -->
        <div v-if="mailConfig.loading" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
          Loading…
        </div>

        <template v-else-if="cfg!">

          <!-- Driver -->
          <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
            <div class="flex flex-col gap-1.5">
              <label for="driver" class="text-sm font-medium">Mail Driver</label>
              <select
                id="driver"
                v-model="cfg!.driver"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option value="smtp">SMTP</option>
                <option value="php_mail">PHP Mail</option>
              </select>
              <p class="text-xs text-muted-foreground">SMTP recommended for production.</p>
            </div>
          </div>

          <!-- SMTP fields (conditional) -->
          <div v-if="isSmtp" class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
            <h2 class="text-sm font-semibold">SMTP Configuration</h2>

            <div class="grid grid-cols-2 gap-4">
              <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-medium">Host</label>
                <input
                  id="host"
                  v-model="cfg!.host"
                  type="text"
                  placeholder="smtp.example.com"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>
              <div class="flex flex-col gap-1.5">
                <label for="port" class="text-sm font-medium">Port</label>
                <input
                  id="port"
                  v-model.number="cfg!.port"
                  type="number"
                  min="1"
                  max="65535"
                  placeholder="587"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="flex flex-col gap-1.5">
                <label for="username" class="text-sm font-medium">Username</label>
                <input
                  id="username"
                  v-model="cfg!.username"
                  type="text"
                  placeholder="user@example.com"
                  autocomplete="off"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>
              <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-medium">Password</label>
                <input
                  id="password"
                  v-model="cfg!.password"
                  type="password"
                  placeholder="Leave blank to keep current"
                  autocomplete="new-password"
                  class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
              </div>
            </div>

            <div class="flex flex-col gap-1.5">
              <label for="encryption" class="text-sm font-medium">Encryption</label>
              <select
                id="encryption"
                v-model="cfg!.encryption"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option v-for="opt in encryptionOptions" :key="String(opt.value)" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>
          </div>

          <!-- From fields -->
          <div class="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 mb-4">
            <h2 class="text-sm font-semibold">Sender Identity</h2>

            <div class="flex flex-col gap-1.5">
              <label for="from-address" class="text-sm font-medium">From Address</label>
              <input
                id="from-address"
                v-model="cfg!.from_address"
                type="email"
                placeholder="noreply@example.com"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>

            <div class="flex flex-col gap-1.5">
              <label for="from-name" class="text-sm font-medium">From Name</label>
              <input
                id="from-name"
                v-model="cfg!.from_name"
                type="text"
                placeholder="Spora"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center justify-between gap-4">
            <button
              @click="testConnection"
              :disabled="mailConfig.testing || !auth.user?.email"
              class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted disabled:pointer-events-none disabled:opacity-50"
            >
              {{ mailConfig.testing ? 'Sending…' : 'Test Connection' }}
            </button>

            <button
              @click="save"
              :disabled="mailConfig.saving"
              class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
            >
              {{ mailConfig.saving ? 'Saving…' : 'Save' }}
            </button>
          </div>

        </template v-else>
        <div v-else class="text-sm text-muted-foreground">No mail configuration found.</div>
      </div>
    </main>
  </div>
</template>