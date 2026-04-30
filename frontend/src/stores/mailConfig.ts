/**
 * mailConfig store — system mail configuration management.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { MailConfig, MailConfigPayload } from '@/types/mailConfig'

export const useMailConfigStore = defineStore('mailConfig', () => {
  const config = ref<MailConfig | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const testing = ref(false)
  const error = ref<string | null>(null)

  async function fetchConfig(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const result = await api.get<{ mail_config: MailConfig }>('/mail-config')
      config.value = result.mail_config
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load mail configuration.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function saveConfig(values: MailConfigPayload): Promise<MailConfig> {
    saving.value = true
    error.value = null
    try {
      const result = await api.patch<{ mail_config: MailConfig }>('/mail-config', values)
      config.value = result.mail_config
      return result.mail_config
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to save mail configuration.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function testConnection(): Promise<string> {
    testing.value = true
    error.value = null
    try {
      const result = await api.post<{ message: string }>('/mail-config/test')
      return result.message
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to send test email.'
      error.value = msg
      throw e
    } finally {
      testing.value = false
    }
  }

  return { config, loading, saving, testing, error, fetchConfig, saveConfig, testConnection }
})
