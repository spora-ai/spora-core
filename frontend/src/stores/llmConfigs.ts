/**
 * llmConfigs store — manages LLM driver configurations.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, ApiError } from '@/api/client'
import type { LLMConfigResource, LLMDriverInfo } from '@/types/llmConfig'

export const useLlmConfigsStore = defineStore('llmConfigs', () => {
  const drivers = ref<LLMDriverInfo[]>([])
  const configs = ref<LLMConfigResource[]>([])
  const loadingDrivers = ref(false)
  const loadingConfigs = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  // Tracks whether initial data has been fetched this session.
  // Prevents duplicate network calls when multiple components mount.
  const initialized = ref(false)

  const globalDefaultConfig = ref<LLMConfigResource | null>(null)

  // Separate list for admin-only global config management (DriversSettingsPage)
  const globalAdminConfigs = ref<LLMConfigResource[]>([])
  const loadingGlobalAdminConfigs = ref(false)

  const globalConfigs = computed(() => configs.value.filter((c) => c.is_global))
  const personalConfigs = computed(() => configs.value.filter((c) => !c.is_global))

  async function loadDrivers(): Promise<void> {
    loadingDrivers.value = true
    error.value = null
    try {
      const result = await api.get<{ drivers: LLMDriverInfo[] }>('/llm-drivers')
      drivers.value = result.drivers
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load drivers.'
    } finally {
      loadingDrivers.value = false
    }
  }

  async function loadConfigs(): Promise<void> {
    loadingConfigs.value = true
    error.value = null
    try {
      const result = await api.get<{ configs: LLMConfigResource[] }>('/llm-configs')
      configs.value = result.configs
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load configurations.'
    } finally {
      loadingConfigs.value = false
    }
  }

  async function loadGlobalDefault(): Promise<void> {
    try {
      const result = await api.get<{ configs: LLMConfigResource[] }>('/llm-configs/global')
      // Pick the first config marked is_default, fall back to first, fall back to null
      const all = result.configs ?? []
      globalDefaultConfig.value = all.find((c) => c.is_default) ?? all[0] ?? null
    } catch {
      globalDefaultConfig.value = null
    }
  }

  async function loadGlobalAdminConfigs(): Promise<void> {
    loadingGlobalAdminConfigs.value = true
    error.value = null
    try {
      const result = await api.get<{ configs: LLMConfigResource[] }>('/llm-configs/global')
      globalAdminConfigs.value = result.configs ?? []
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load global configurations.'
    } finally {
      loadingGlobalAdminConfigs.value = false
    }
  }

  // Load drivers + configs exactly once per Pinia instance (page session).
  // Safe to call from multiple components — subsequent calls are no-ops.
  async function ensure(): Promise<void> {
    if (initialized.value) return
    initialized.value = true // set before await so parallel callers skip
    await Promise.all([loadDrivers(), loadConfigs(), loadGlobalDefault()])
  }

  async function createConfig(payload: {
    name: string
    driver_class: string
    settings: Record<string, string>
    is_default?: boolean
    is_global?: boolean
  }): Promise<LLMConfigResource> {
    saving.value = true
    error.value = null
    try {
      const result = await api.post<{ config: LLMConfigResource }>('/llm-configs', payload)
      if (result.config.is_default) {
        configs.value = configs.value.map((c) => ({ ...c, is_default: false }))
      }
      configs.value.push(result.config)
      return result.config
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to create configuration.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateConfig(
    id: number,
    payload: { name?: string; settings?: Record<string, string> },
  ): Promise<LLMConfigResource> {
    saving.value = true
    error.value = null
    try {
      const result = await api.put<{ config: LLMConfigResource }>(`/llm-configs/${id}`, payload)
      const idx = configs.value.findIndex((c) => c.id === id)
      if (idx !== -1) {
        configs.value[idx] = result.config
      }
      return result.config
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to update configuration.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteConfig(id: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.delete(`/llm-configs/${id}`)
      configs.value = configs.value.filter((c) => c.id !== id)
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to delete configuration.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  function driverForClass(driverClass: string): LLMDriverInfo | undefined {
    return drivers.value.find((d) => d.driver_class === driverClass)
  }

  function driverByName(name: string): LLMDriverInfo | undefined {
    return drivers.value.find((d) => d.name === name)
  }

  return {
    drivers,
    configs,
    loadingDrivers,
    loadingConfigs,
    saving,
    error,
    initialized,
    globalConfigs,
    personalConfigs,
    globalDefaultConfig,
    globalAdminConfigs,
    loadingGlobalAdminConfigs,
    loadDrivers,
    loadConfigs,
    loadGlobalDefault,
    loadGlobalAdminConfigs,
    ensure,
    createConfig,
    updateConfig,
    deleteConfig,
    driverForClass,
    driverByName,
  }
})
