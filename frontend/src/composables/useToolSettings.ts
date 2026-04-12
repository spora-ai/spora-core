/**
 * useToolSettings — API layer for reading/writing tool settings.
 *
 * Global mode (no agentId):  → GET/PUT /tools/{toolId}/settings
 * Per-agent mode (agentId): → GET/PUT /agents/{agentId}/tools/{toolId}/override
 *
 * Password fields are write-only on the server — GET returns "***" for masked keys.
 * When saving, "***" means "leave unchanged", empty string "" means "clear this value".
 */
import { api } from '@/api/client'
import { ApiError } from '@/api/client'

export interface ToolSettingSchema {
  key: string
  label: string
  type: 'text' | 'password' | 'select' | 'textarea' | 'toggle'
  description: string
  default: unknown
  required: boolean
  scope: 'global' | 'agent'
  options: string[] | null
}

export interface ToolSchema {
  tool_class: string
  tool_name: string
  display_name: string | null
  settings_schema: ToolSettingSchema[]
}

export interface ToolStatus {
  tool_class: string
  is_enabled: boolean
  missing_required: string[]
  can_enable: boolean
}

export interface SettingsWithSource {
  [key: string]: {
    value: string | boolean | null
    source: 'global' | 'agent' | 'default'
  }
}

export function useToolSettings(agentId?: number) {
  // ── Helpers ────────────────────────────────────────────────────────────────

  function isGlobal(): boolean {
    return agentId === undefined
  }

  function settingsPath(toolId: string): string {
    if (isGlobal()) {
      return `/tools/${encodeURIComponent(toolId)}/settings`
    }
    return `/agents/${agentId}/tools/${encodeURIComponent(toolId)}/override`
  }

  // ── Fetch current settings for a tool ─────────────────────────────────────

  async function getSettings(toolId: string): Promise<Record<string, string>> {
    try {
      const result = await api.get<{ settings: Record<string, string> }>(settingsPath(toolId))
      return result.settings ?? {}
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        return {}
      }
      throw e
    }
  }

  // ── Save settings for a tool ───────────────────────────────────────────────
  //
  // callerSettings: the full form state (key → value).
  // serverSettings: the current settings from GET (may contain "***" for unchanged passwords).
  //
  // Strategy: diff against serverSettings.
  //  - If value === serverValue → omit (no change needed)
  //  - If value === '' and serverValue === '***' → user cleared the field → send ''
  //  - If value !== serverValue and value !== '' → user changed it → send value
  //
  // For new keys not in serverSettings → always send (they're new).
  // For serverSettings keys not in callerSettings → they shouldn't happen (form has all keys).
  //
  async function putSettings(
    toolId: string,
    callerSettings: Record<string, string>,
    serverSettings?: Record<string, string>,
  ): Promise<Record<string, string>> {
    const current = serverSettings ?? {}

    const toSave: Record<string, string> = {}
    for (const [key, value] of Object.entries(callerSettings)) {
      const serverValue = current[key]

      // Password field: "***" from server means "masked / unchanged"
      if (serverValue === '***') {
        if (value === '') {
          // User explicitly cleared the password → send empty string to clear
          toSave[key] = ''
        } else if (value !== '***') {
          // User typed a new value → send it
          toSave[key] = value
        }
        continue
      }

      // Non-password or non-masked value changed → send it
      if (value !== serverValue) {
        toSave[key] = value
      }
    }

    const result = await api.put<{ settings: Record<string, string> }>(
      settingsPath(toolId),
      { settings: toSave },
    )
    return result.settings ?? {}
  }

  // ── Agent-specific helpers (only when agentId is provided) ─────────────────

  async function getToolStatus(toolId: string): Promise<ToolStatus | null> {
    if (isGlobal()) {
      return null
    }
    try {
      return await api.get<ToolStatus>(
        `/agents/${agentId}/tools/${encodeURIComponent(toolId)}/status`,
      )
    } catch {
      // Return null for any error — lets caller decide how to handle
      return null
    }
  }

  async function getRawOverride(toolId: string): Promise<Record<string, string>> {
    if (isGlobal()) {
      return {}
    }
    try {
      const result = await api.get<{ settings: Record<string, string> }>(
        `/agents/${agentId}/tools/${encodeURIComponent(toolId)}/override?raw=true`,
      )
      return result.settings ?? {}
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        return {}
      }
      throw e
    }
  }

  async function getGlobalSettings(toolId: string): Promise<Record<string, string>> {
    try {
      const result = await api.get<{ settings: Record<string, string> }>(
        `/tools/${encodeURIComponent(toolId)}/settings`,
      )
      return result.settings ?? {}
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        return {}
      }
      throw e
    }
  }

  async function getSettingsWithSource(toolId: string): Promise<SettingsWithSource> {
    if (isGlobal()) {
      return {}
    }
    try {
      const result = await api.get<{ settings: SettingsWithSource }>(
        `/agents/${agentId}/tools/${encodeURIComponent(toolId)}/override`,
      )
      return result.settings ?? {}
    } catch (e) {
      return {}
    }
  }

  return {
    getSettings,
    putSettings,
    getToolStatus,
    getRawOverride,
    getGlobalSettings,
    getSettingsWithSource,
  }
}
