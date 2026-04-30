<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import { useAdminAuth } from '@/composables/useAdminAuth'
import LLMConfigList from '@/components/settings/llm/LLMConfigList.vue'
import LLMConfigCreateForm from '@/components/settings/llm/LLMConfigCreateForm.vue'
import LLMConfigEditForm from '@/components/settings/llm/LLMConfigEditForm.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import AdminSection from '@/components/admin/AdminSection.vue'
import AdminForbidden from '@/components/admin/AdminForbidden.vue'
import type { LLMConfigResource } from '@/types/llmConfig'

const { isAdmin } = useAdminAuth()
const route = useRoute()
const router = useRouter()
const llmStore = useLlmConfigsStore()

type ViewMode = 'list' | 'create' | 'edit'
const viewMode = ref<ViewMode>('list')
const selectedConfigId = ref<number | null>(null)

// Admin page shows ONLY global configs (not personal)
const selectedConfig = computed<LLMConfigResource | null>(
  () => llmStore.globalAdminConfigs.find((c) => c.id === selectedConfigId.value) ?? null,
)

function applyQueryParams(): void {
  const configParam = route.query.config
  const createParam = route.query.create

  if (createParam === '1') {
    viewMode.value = 'create'
    selectedConfigId.value = null
  } else if (configParam) {
    const id = Number(configParam)
    const config = llmStore.globalAdminConfigs.find((c) => c.id === id)
    if (config) {
      selectedConfigId.value = id
      viewMode.value = 'edit'
    }
  } else {
    viewMode.value = 'list'
    selectedConfigId.value = null
  }
}

onMounted(async () => {
  // Load drivers for schema + global configs for admin management
  await Promise.all([
    llmStore.loadDrivers(),
    llmStore.loadGlobalAdminConfigs(),
  ])
  applyQueryParams()
})

watch(() => [route.query.config, route.query.create], applyQueryParams)

function selectConfig(config: LLMConfigResource): void {
  selectedConfigId.value = config.id
  viewMode.value = 'edit'
  router.replace({ name: 'settings-admin-drivers', query: { config: String(config.id) } })
}

function startCreate(): void {
  selectedConfigId.value = null
  viewMode.value = 'create'
  router.replace({ name: 'settings-admin-drivers', query: { create: '1' } })
}

function onCreated(config: LLMConfigResource): void {
  selectedConfigId.value = config.id
  viewMode.value = 'edit'
  router.replace({ name: 'settings-admin-drivers', query: { config: String(config.id) } })
}

function onDeleted(): void {
  selectedConfigId.value = null
  viewMode.value = 'list'
  router.replace({ name: 'settings-admin-drivers' })
}

function cancel(): void {
  viewMode.value = 'list'
  selectedConfigId.value = null
  router.replace({ name: 'settings-admin-drivers' })
}
</script>

<template>
  <AdminForbidden v-if="!isAdmin" />

  <AdminSection v-else title="LLM Drivers" description="Manage global LLM driver configurations available to all users.">
    <AlertBanner v-if="llmStore.error" type="error" :message="llmStore.error" class="mb-4" />

    <!-- List view — only global configs -->
    <template v-if="viewMode === 'list'">
      <div v-if="llmStore.loadingGlobalAdminConfigs" class="text-sm text-muted-foreground py-8 text-center">
        Loading…
      </div>
      <template v-else>
        <!-- Empty state -->
        <div v-if="llmStore.globalAdminConfigs.length === 0" class="rounded-xl border border-border bg-card p-8 text-center">
          <p class="text-sm text-muted-foreground mb-4">No global LLM configurations yet.</p>
          <button
            @click="startCreate"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
          >
            Create the first global configuration
          </button>
        </div>
        <!-- Config list -->
        <div v-else class="rounded-xl border border-border bg-card divide-y divide-border">
          <button
            v-for="config in llmStore.globalAdminConfigs"
            :key="config.id"
            type="button"
            @click="selectConfig(config)"
            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-muted/50 transition-colors"
          >
            <div>
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium">{{ config.name }}</span>
                <span
                  v-if="config.is_default"
                  class="text-xs rounded-full bg-accent/10 text-accent px-1.5 py-0.5 font-medium"
                >
                  Global Default
                </span>
              </div>
              <p class="text-xs text-muted-foreground mt-0.5">{{ config.driver_display_name }}</p>
            </div>
            <span class="text-muted-foreground">→</span>
          </button>
        </div>
        <div class="mt-4 flex justify-end">
          <button
            @click="startCreate"
            class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
          >
            + Add Global Config
          </button>
        </div>
      </template>
    </template>

    <!-- Create form -->
    <LLMConfigCreateForm
      v-else-if="viewMode === 'create'"
      requireGlobal
      @created="onCreated"
      @cancel="cancel"
    />

    <!-- Edit form -->
    <LLMConfigEditForm
      v-else-if="viewMode === 'edit' && selectedConfig"
      :key="selectedConfig.id"
      :config="selectedConfig"
      @saved="() => {}"
      @deleted="onDeleted"
      @cancel="cancel"
    />
  </AdminSection>
</template>
