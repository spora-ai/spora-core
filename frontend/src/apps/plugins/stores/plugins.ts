import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError } from '@/api/client'
import type { PluginResource } from '../types/plugin'
import { getPlugins } from '../api/plugins'

/**
 * Inventory of installed plugins. Read-only in v1: load the list once on
 * mount and expose it to the page. Refresh is supported so a future
 * "Re-scan" button can be wired up without changing the shape.
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
