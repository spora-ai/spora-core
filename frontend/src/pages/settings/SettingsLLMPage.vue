<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useLlmConfigsStore } from '@/stores/llmConfigs'
import LLMConfigList from '@/components/settings/llm/LLMConfigList.vue'
import LLMConfigCreateForm from '@/components/settings/llm/LLMConfigCreateForm.vue'
import LLMConfigEditForm from '@/components/settings/llm/LLMConfigEditForm.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import type { LLMConfigResource } from '@/types/llmConfig'

const route = useRoute()
const router = useRouter()
const llmStore = useLlmConfigsStore()

type ViewMode = 'list' | 'create' | 'edit'
const viewMode = ref<ViewMode>('list')
const selectedConfigId = ref<number | null>(null)

// Always reflects the latest version of the config from the store
const selectedConfig = computed<LLMConfigResource | null>(
  () => llmStore.configs.find((c) => c.id === selectedConfigId.value) ?? null,
)

// Apply query params to determine view mode.
// No params → list view; this handles sidebar "LLM" top-nav clicks while in edit/create.
function applyQueryParams(): void {
  const configParam = route.query.config
  const createParam = route.query.create

  if (createParam === '1') {
    viewMode.value = 'create'
    selectedConfigId.value = null
  } else if (configParam) {
    const id = Number(configParam)
    const config = llmStore.configs.find((c) => c.id === id)
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
  // ensure() is idempotent — no-op if layout already loaded the data
  await llmStore.ensure()
  applyQueryParams()
})

// React when navigating between configs via sidebar without leaving the route
watch(() => [route.query.config, route.query.create], applyQueryParams)

function selectConfig(config: LLMConfigResource): void {
  selectedConfigId.value = config.id
  viewMode.value = 'edit'
  router.replace({ name: 'settings-llm', query: { config: String(config.id) } })
}

function startCreate(): void {
  selectedConfigId.value = null
  viewMode.value = 'create'
  router.replace({ name: 'settings-llm', query: { create: '1' } })
}

function onCreated(config: LLMConfigResource): void {
  selectedConfigId.value = config.id
  viewMode.value = 'edit'
  router.replace({ name: 'settings-llm', query: { config: String(config.id) } })
}

function onDeleted(): void {
  selectedConfigId.value = null
  viewMode.value = 'list'
  router.replace({ name: 'settings-llm' })
}

function cancel(): void {
  viewMode.value = 'list'
  selectedConfigId.value = null
  router.replace({ name: 'settings-llm' })
}
</script>

<template>
  <AlertBanner v-if="llmStore.error" type="error" :message="llmStore.error" class="mb-4" />

  <!-- List view -->
  <template v-if="viewMode === 'list'">
    <div class="mb-6">
      <h1 class="text-lg font-semibold">LLM Providers</h1>
      <p class="text-sm text-muted-foreground mt-0.5">Manage your LLM provider configurations.</p>
    </div>
    <LLMConfigList @select="selectConfig" @create="startCreate" />
  </template>

  <!-- Create form -->
  <LLMConfigCreateForm
    v-else-if="viewMode === 'create'"
    @created="onCreated"
    @cancel="cancel"
  />

  <!-- Edit form: key forces remount only when switching to a different config -->
  <LLMConfigEditForm
    v-else-if="viewMode === 'edit' && selectedConfig"
    :key="selectedConfig.id"
    :config="selectedConfig"
    @saved="() => {}"
    @deleted="onDeleted"
    @cancel="cancel"
  />
</template>
