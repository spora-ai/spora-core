/**
 * llmPreferences store — manages the user's preferred LLM configuration.
 * This is separate from llmConfigs store because preferences can reference
 * any config (personal OR global) the user has access to.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { LLMConfigResource } from '@/types/llmConfig'

interface PreferenceResponse {
  config: LLMConfigResource | null
}

export const useLlmPreferencesStore = defineStore('llmPreferences', () => {
  const preference = ref<{ config: LLMConfigResource } | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function loadPreference(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const result = await api.get<PreferenceResponse>('/user-preferences/llm')
      preference.value = result.config ? { config: result.config } : null
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load LLM preference.'
    } finally {
      loading.value = false
    }
  }

  async function setPreference(configId: number | null): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const result = await api.put<PreferenceResponse>('/user-preferences/llm', {
        config_id: configId,
      })
      preference.value = result.config ? { config: result.config } : null
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to set LLM preference.'
      error.value = msg
      throw e
    } finally {
      loading.value = false
    }
  }

  async function clearPreference(): Promise<void> {
    await setPreference(null)
  }

  return {
    preference,
    loading,
    error,
    loadPreference,
    setPreference,
    clearPreference,
  }
})