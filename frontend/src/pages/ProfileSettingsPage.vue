<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import GlobalNavbar from '@/components/GlobalNavbar.vue'
import { ApiError, api } from '@/api/client'

interface UserProfile {
  name: string | null
  date_of_birth: string | null
  about_me: string | null
  height_cm: number | null
  weight_kg: number | null
}

interface UserLocation {
  id: number
  name: string
  address: string
  is_default: boolean
}

// ── Profile form ─────────────────────────────────────────────────────────────

const profile = ref<UserProfile>({
  name: null,
  date_of_birth: null,
  about_me: null,
  height_cm: null,
  weight_kg: null,
})
const profileLoading = ref(false)
const profileSaving = ref(false)
const profileError = ref<string | null>(null)
const profileSuccess = ref(false)
const healthSuccess = ref(false)

// ── Locations ────────────────────────────────────────────────────────────────

const locations = ref<UserLocation[]>([])
const locationsLoading = ref(false)
const locationsError = ref<string | null>(null)

const showLocationForm = ref(false)
const editingLocation = ref<number | null>(null)

const locationForm = ref({
  name: '',
  address: '',
  is_default: false,
})
const locationFormError = ref<string | null>(null)
const locationFormSaving = ref(false)

function openAddLocation(): void {
  locationForm.value = { name: '', address: '', is_default: false }
  editingLocation.value = null
  showLocationForm.value = true
}

function openEditLocation(loc: UserLocation): void {
  locationForm.value = {
    name: loc.name,
    address: loc.address,
    is_default: loc.is_default,
  }
  editingLocation.value = loc.id
  showLocationForm.value = true
}

function closeLocationForm(): void {
  showLocationForm.value = false
  editingLocation.value = null
  locationFormError.value = null
}

async function saveLocation(): Promise<void> {
  if (!locationForm.value.name.trim()) {
    locationFormError.value = 'Name is required.'
    return
  }
  if (!locationForm.value.address.trim()) {
    locationFormError.value = 'Address is required.'
    return
  }
  locationFormSaving.value = true
  locationFormError.value = null
  try {
    if (editingLocation.value !== null) {
      const res = await api.put<UserLocation>(`/me/locations/${editingLocation.value}`, locationForm.value)
      const idx = locations.value.findIndex((l) => l.id === editingLocation.value)
      if (idx !== -1) {
        locations.value[idx] = res
      }
    } else {
      const res = await api.post<UserLocation>('/me/locations', locationForm.value)
      locations.value.push(res)
    }
    closeLocationForm()
  } catch (e: any) {
    locationFormError.value = e instanceof ApiError ? e.message : 'Failed to save location.'
  } finally {
    locationFormSaving.value = false
  }
}

async function deleteLocation(id: number): Promise<void> {
  try {
    await api.delete(`/me/locations/${id}`)
    locations.value = locations.value.filter((l) => l.id !== id)
  } catch (e: any) {
    locationsError.value = e instanceof ApiError ? e.message : 'Failed to delete location.'
  }
}

// ── Load data ────────────────────────────────────────────────────────────────

onMounted(async () => {
  profileLoading.value = true
  locationsLoading.value = true

  try {
    const [profileRes, locationsRes] = await Promise.all([
      api.get<UserProfile>('/me/profile'),
      api.get<{ locations: UserLocation[] }>('/me/locations'),
    ])

    profile.value = profileRes
    locations.value = locationsRes.locations ?? []
  } catch (e: any) {
    profileError.value = e instanceof ApiError ? e.message : 'Failed to load profile.'
    locationsError.value = e instanceof ApiError ? e.message : 'Failed to load locations.'
  } finally {
    profileLoading.value = false
    locationsLoading.value = false
  }
})

// ── Save profile ─────────────────────────────────────────────────────────────

async function saveProfile(): Promise<void> {
  profileSaving.value = true
  profileError.value = null
  profileSuccess.value = false
  try {
    const res = await api.put<UserProfile>('/me/profile', profile.value)
    profile.value = res
    profileSuccess.value = true
    setTimeout(() => { profileSuccess.value = false }, 3000)
  } catch (e: any) {
    profileError.value = e instanceof ApiError ? e.message : 'Failed to save profile.'
  } finally {
    profileSaving.value = false
  }
}

async function saveHealthData(): Promise<void> {
  profileSaving.value = true
  profileError.value = null
  healthSuccess.value = false
  try {
    const res = await api.put<UserProfile>('/me/profile', profile.value)
    profile.value = res
    healthSuccess.value = true
    setTimeout(() => { healthSuccess.value = false }, 3000)
  } catch (e: any) {
    profileError.value = e instanceof ApiError ? e.message : 'Failed to save health data.'
  } finally {
    profileSaving.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-background flex flex-col">
    <GlobalNavbar />
    <main class="flex-1 max-w-2xl mx-auto w-full px-4 py-8">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-semibold">Profile</h1>
      </div>
      <div class="space-y-6">

        <!-- ── Base Data ──────────────────────────────────────────────────── -->
        <section class="rounded-xl border border-border bg-card p-5 space-y-4">
          <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Base Data</h3>
          <div v-if="profileLoading" class="text-sm text-muted-foreground">Loading…</div>
          <div v-else class="space-y-3">
            <div class="flex flex-col gap-1.5">
              <label for="profile-name" class="text-sm font-medium">Name for AI Agents</label>
              <input
                id="profile-name"
                v-model="profile.name"
                type="text"
                placeholder="How agents address you"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
              <p class="text-xs text-muted-foreground">Personalizes how agents refer to you in conversations.</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="profile-dob" class="text-sm font-medium">Date of Birth</label>
              <input
                id="profile-dob"
                v-model="profile.date_of_birth"
                type="date"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="profile-about" class="text-sm font-medium">About Me</label>
              <textarea
                id="profile-about"
                v-model="profile.about_me"
                rows="3"
                placeholder="A short description about yourself…"
                class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <div class="flex items-center justify-between">
              <p v-if="profileError" role="alert" class="text-xs text-destructive">{{ profileError }}</p>
              <span v-else-if="profileSuccess" class="text-xs text-green-600">Saved!</span>
              <span v-else />
              <button
                @click="saveProfile"
                :disabled="profileSaving"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
              >
                {{ profileSaving ? 'Saving…' : 'Save Base Data' }}
              </button>
            </div>
          </div>
        </section>

        <!-- ── Locations ──────────────────────────────────────────────────── -->
        <section class="rounded-xl border border-border bg-card p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Locations</h3>
            <button
              @click="openAddLocation"
              class="inline-flex h-7 items-center gap-1 text-xs font-medium text-primary hover:text-primary/80 transition-colors"
            >
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
              </svg>
              Add location
            </button>
          </div>

          <div v-if="locationsLoading" class="text-sm text-muted-foreground">Loading…</div>
          <div v-else-if="locations.length === 0" class="text-sm text-muted-foreground">
            No locations saved yet.
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="loc in locations"
              :key="loc.id"
              class="flex items-start justify-between p-3 rounded-lg border border-border gap-3"
            >
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-medium truncate">{{ loc.name }}</p>
                  <span v-if="loc.is_default" class="text-xs bg-muted text-muted-foreground px-1.5 py-0.5 rounded">default</span>
                </div>
                <p class="text-xs text-muted-foreground mt-0.5 truncate">{{ loc.address }}</p>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <button
                  @click="openEditLocation(loc)"
                  class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                >
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </button>
                <button
                  @click="deleteLocation(loc.id)"
                  class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:text-destructive hover:bg-muted transition-colors"
                >
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
          <p v-if="locationsError" role="alert" class="text-xs text-destructive">{{ locationsError }}</p>
        </section>

        <!-- ── Health Data ────────────────────────────────────────────────── -->
        <section class="rounded-xl border border-border bg-card p-5 space-y-4">
          <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Health Data</h3>
          <div class="space-y-3">
            <div class="flex flex-col gap-1.5">
              <label for="health-height" class="text-sm font-medium">Height (cm)</label>
              <input
                id="health-height"
                v-model="profile.height_cm"
                type="number"
                min="0"
                step="0.01"
                placeholder="e.g. 175.5"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="health-weight" class="text-sm font-medium">Weight (kg)</label>
              <input
                id="health-weight"
                v-model="profile.weight_kg"
                type="number"
                min="0"
                step="0.01"
                placeholder="e.g. 70.5"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
              />
            </div>
            <div class="flex items-center justify-between">
              <p v-if="profileError" role="alert" class="text-xs text-destructive">{{ profileError }}</p>
              <span v-else-if="healthSuccess" class="text-xs text-green-600">Saved!</span>
              <span v-else />
              <button
                @click="saveHealthData"
                :disabled="profileSaving"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
              >
                {{ profileSaving ? 'Saving…' : 'Save Health Data' }}
              </button>
            </div>
          </div>
        </section>

      </div>
    </main>

    <!-- ── Location Form Modal ─────────────────────────────────────────────── -->
    <div
      v-if="showLocationForm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="closeLocationForm"
    >
      <div class="w-full max-w-sm rounded-xl border border-border bg-background p-5 space-y-4 shadow-xl">
        <h3 class="text-sm font-semibold">{{ editingLocation !== null ? 'Edit Location' : 'Add Location' }}</h3>
        <div class="space-y-3">
          <div class="flex flex-col gap-1.5">
            <label for="loc-name" class="text-sm font-medium">Name *</label>
            <input
              id="loc-name"
              v-model="locationForm.name"
              type="text"
              placeholder="e.g. Home, Work, Beach House"
              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="loc-address" class="text-sm font-medium">Address *</label>
            <textarea
              id="loc-address"
              v-model="locationForm.address"
              rows="2"
              placeholder="Full address"
              class="w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>
          <div class="flex items-center gap-2">
            <input
              id="loc-default"
              v-model="locationForm.is_default"
              type="checkbox"
              class="h-4 w-4 rounded border-border bg-background text-primary focus:ring-1 focus:ring-ring"
            />
            <label for="loc-default" class="text-sm font-medium">Set as default</label>
          </div>
        </div>
        <p v-if="locationFormError" role="alert" class="text-xs text-destructive">{{ locationFormError }}</p>
        <div class="flex justify-end gap-2">
          <button
            @click="closeLocationForm"
            class="inline-flex h-9 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-medium shadow transition-colors hover:bg-muted"
          >
            Cancel
          </button>
          <button
            @click="saveLocation"
            :disabled="locationFormSaving"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
          >
            {{ locationFormSaving ? 'Saving…' : 'Save Location' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
