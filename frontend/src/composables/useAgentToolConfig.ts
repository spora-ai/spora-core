import type { ToolSchema, SettingsWithSource } from '@/composables/useToolSettings'

/**
 * Pure helpers for AgentToolConfigModal — form initialization, payload
 * building, and source/badge formatting. No Vue lifecycle, no DOM, no
 * store dispatch.
 */

/**
 * Build the payload for PUT /agents/{agentId}/tools/{toolName}/override
 *
 * Sends all schema fields:
 * - Non-empty values are sent as-is
 * - Empty/omitted values become null (meaning "use parent value")
 */
export function buildAgentOverridePayload(
  tool: ToolSchema,
  form: Record<string, string | null | undefined>,
): Record<string, string | null> {
  const payload: Record<string, string | null> = {}

  for (const field of tool.settings_schema) {
    const value = form[field.key]
    // Empty/null means "use parent" - send null
    payload[field.key] = value === '' || value === null || value === undefined ? null : value
  }

  return payload
}

/**
 * Initialize form state from settings with source annotation.
 * Returns the form values with source = 'agent' pre-filled.
 *
 * Array values (e.g. multi-select settings) are JSON-stringified so the
 * form keeps its `Record<string, string>` shape while preserving the
 * structure of the array. The form input components are responsible for
 * parsing the string back when they need an array.
 */
export function initFormFromSettingsWithSource(
  settingsWithSource: SettingsWithSource,
): Record<string, string> {
  const form: Record<string, string> = {}

  for (const [key, item] of Object.entries(settingsWithSource)) {
    if (item.source === 'agent') {
      if (item.value == null) {
        form[key] = ''
      } else if (Array.isArray(item.value)) {
        form[key] = JSON.stringify(item.value)
      } else {
        form[key] = String(item.value)
      }
    }
  }

  return form
}

/**
 * Initialize the form from the effective settings-with-source map. Kept as
 * an alias for callers that want a more declarative name.
 */
export const resolveInitialForm = initFormFromSettingsWithSource

/**
 * Return a shallow record of "has this key been overridden by the agent?"
 * derived from the raw override map. Used to render the "Remove all
 * overrides" affordance.
 */
export function diffAgainst(
  rawOverride: Record<string, unknown>,
): { agentOverridesExist: boolean; overrideKeys: string[] } {
  const overrideKeys = Object.keys(rawOverride)
  return { agentOverridesExist: overrideKeys.length > 0, overrideKeys }
}

/**
 * Build the body for PUT /agents/{id}/tools/{toolName}/override.
 * The endpoint expects `{ settings: { ... } }` — the inner object is the
 * result of `buildAgentOverridePayload`.
 */
export function buildSubmitPayload(
  tool: ToolSchema,
  form: Record<string, string | null | undefined>,
): { settings: Record<string, string | null> } {
  return { settings: buildAgentOverridePayload(tool, form) }
}

/** Look up the source of a single key in the settings-with-source map. */
export function getSource(
  settingsWithSource: SettingsWithSource,
  key: string,
): 'global' | 'user' | 'agent' | 'default' {
  return settingsWithSource[key]?.source ?? 'default'
}

/** Mask a password-typed field for the read-only "current settings" panel. */
export function maskPasswordValue(value: unknown, isPassword: boolean): string {
  if (value === null || value === undefined) return '—'
  if (isPassword) return '••••••••'
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  if (Array.isArray(value)) return JSON.stringify(value)
  if (typeof value === 'object') return JSON.stringify(value)
  return '—'
}

/** Tailwind class string for a source badge. */
export function getSourceBadgeClass(source: string): string {
  switch (source) {
    case 'agent': return 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'
    case 'user': return 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
    case 'global': return 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
    default: return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'
  }
}

/** Human-readable label for a source badge. */
export function getSourceLabel(source: string): string {
  switch (source) {
    case 'agent': return 'Agent'
    case 'user': return 'User'
    case 'global': return 'Global'
    default: return 'Default'
  }
}

/** Whether any of the effective settings deviate from defaults. */
export function hasAnyEffectiveSettings(
  settingsWithSource: SettingsWithSource,
): boolean {
  return Object.values(settingsWithSource).some((item) => item.source !== 'default')
}

/** Whether a given field key in a tool's schema is a password field. */
export function isPasswordField(tool: ToolSchema | null, key: string): boolean {
  return tool?.settings_schema.find((f) => f.key === key)?.type === 'password' || false
}
