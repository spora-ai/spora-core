import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError } from '@/api/client'
import type { PluginResource } from '../types/plugin'
import { getPlugins } from '../api/plugins'

/**
 * Inventory of installed plugins. Read-only in v1: the page calls `load()`
 * on mount and again from the Refresh button.
 */
export const usePluginsStore = defineStore('plugins', () => {
  const plugins = ref<PluginResource[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      plugins.value = await getPlugins()
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load plugins.'
    } finally {
      loading.value = false
    }
  }

  return {
    plugins,
    loading,
    error,
    load,
  }
})
