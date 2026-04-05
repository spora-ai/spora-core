/**
 * llmConfigs store — manages LLM driver configurations.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { LLMConfigResource, LLMDriverInfo } from '@/types/llmConfig'

export const useLlmConfigsStore = defineStore('llmConfigs', () => {
  // ── State ─────────────────────────────────────────────────────────────────

  const drivers = ref<LLMDriverInfo[]>([])
  const configs = ref<LLMConfigResource[]>([])
  const loadingDrivers = ref(false)
  const loadingConfigs = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  // ── Load drivers (schema discovery, no auth) ──────────────────────────────

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

  // ── Load configs (auth required) ──────────────────────────────────────────

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

  // ── Create ────────────────────────────────────────────────────────────────

  async function createConfig(payload: {
    name: string
    driver_class: string
    settings: Record<string, string>
  }): Promise<LLMConfigResource> {
    saving.value = true
    error.value = null
    try {
      const result = await api.post<{ config: LLMConfigResource }>('/llm-configs', payload)
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

  // ── Update ───────────────────────────────────────────────────────────────

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

  // ── Delete ────────────────────────────────────────────────────────────────

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

  // ── Set as default ────────────────────────────────────────────────────────

  async function setDefault(id: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.post<{ config: LLMConfigResource }>(`/llm-configs/${id}/set-default`)
      // Update is_default on all configs
      configs.value = configs.value.map((c) => ({
        ...c,
        is_default: c.id === id,
      }))
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to set as default.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

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
    loadDrivers,
    loadConfigs,
    createConfig,
    updateConfig,
    deleteConfig,
    setDefault,
    driverForClass,
    driverByName,
  }
})
