/**
 * useGlobalSettingsStore — admin global settings for LLM drivers and tool defaults.
 *
 * LLM driver settings: uses the user's own LLM configs as global defaults.
 * Tool defaults: reads/writes global tool settings via ToolConfigService API.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { LLMDriverInfo } from '@/types/llmConfig'
import { useToolSettings } from '@/composables/useToolSettings'
import type { ToolSchema } from '@/composables/useToolSettings'
import { useAuthStore } from '@/stores/auth'

export const useGlobalSettingsStore = defineStore('globalSettings', () => {
  const drivers = ref<LLMDriverInfo[]>([])
  const driverSettings = ref<Record<string, Record<string, string>>>({})
  const loadingDrivers = ref(false)
  const savingDriver = ref<string | null>(null)
  const driverError = ref<string | null>(null)

  const allTools = ref<ToolSchema[]>([])
  const toolSettings = ref<Record<string, Record<string, string>>>({})
  const loadingTools = ref(false)
  const savingTool = ref<string | null>(null)
  const toolError = ref<string | null>(null)

  async function loadDrivers(): Promise<void> {
    loadingDrivers.value = true
    driverError.value = null
    try {
      const result = await api.get<{ drivers: LLMDriverInfo[] }>('/llm-drivers')
      drivers.value = result.drivers ?? []
    } catch (e) {
      driverError.value = e instanceof ApiError ? e.message : 'Failed to load drivers.'
    } finally {
      loadingDrivers.value = false
    }
  }

  async function loadDriverSettings(): Promise<void> {
    const toolSettingsApi = useToolSettings()
    await Promise.all(
      drivers.value.map(async (driver) => {
        try {
          const settings = await toolSettingsApi.getGlobalSettings(driver.name)
          driverSettings.value[driver.name] = settings
        } catch {
          driverSettings.value[driver.name] = {}
        }
      }),
    )
  }

  async function saveDriverSettings(driver: LLMDriverInfo): Promise<void> {
    savingDriver.value = driver.name
    driverError.value = null
    try {
      const toolSettingsApi = useToolSettings()
      const current = driverSettings.value[driver.name] ?? {}
      const saved = await toolSettingsApi.putSettings(driver.name, current, current)
      driverSettings.value[driver.name] = saved
    } catch (e) {
      driverError.value = e instanceof ApiError ? e.message : 'Failed to save driver settings.'
      throw e
    } finally {
      savingDriver.value = null
    }
  }

  async function loadTools(): Promise<void> {
    loadingTools.value = true
    toolError.value = null
    try {
      const auth = useAuthStore()
      if (!auth.initialized) {
        await auth.init()
      }
      const result = await api.get<{ tools: ToolSchema[] }>('/tools')
      allTools.value = result.tools ?? []
    } catch (e) {
      toolError.value = e instanceof ApiError ? e.message : 'Failed to load tools.'
    } finally {
      loadingTools.value = false
    }
  }

  async function loadToolSettings(): Promise<void> {
    // Ensure tools are loaded first (may be called before loadTools in parallel paths)
    if (allTools.value.length === 0) {
      await loadTools()
    }
    const toolSettingsApi = useToolSettings()
    await Promise.all(
      allTools.value
        .filter((t) => t.settings_schema.length > 0)
        .map(async (tool) => {
          try {
            const settings = await toolSettingsApi.getGlobalSettings(tool.tool_name)
            toolSettings.value[tool.tool_name] = settings
          } catch {
            toolSettings.value[tool.tool_name] = {}
          }
        }),
    )
  }

  async function saveToolSettings(tool: ToolSchema): Promise<void> {
    savingTool.value = tool.tool_name
    toolError.value = null
    try {
      const toolSettingsApi = useToolSettings()
      const current = toolSettings.value[tool.tool_name] ?? {}
      const saved = await toolSettingsApi.putSettings(tool.tool_name, current, current)
      toolSettings.value[tool.tool_name] = saved
    } catch (e) {
      toolError.value = e instanceof ApiError ? e.message : 'Failed to save tool settings.'
      throw e
    } finally {
      savingTool.value = null
    }
  }

  async function loadAll(): Promise<void> {
    await Promise.all([loadDrivers(), loadTools()])
    await Promise.all([loadDriverSettings(), loadToolSettings()])
  }

  return {
    // LLM drivers
    drivers,
    driverSettings,
    loadingDrivers,
    savingDriver,
    driverError,
    loadDrivers,
    loadDriverSettings,
    saveDriverSettings,
    // Tool defaults
    allTools,
    toolSettings,
    loadingTools,
    savingTool,
    toolError,
    loadTools,
    loadToolSettings,
    saveToolSettings,
    // Bulk
    loadAll,
  }
})
