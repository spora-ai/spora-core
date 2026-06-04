/**
 * useToolSettingsPanel — pure helpers for ToolSettingsPanel.
 *
 * Owns the password-mask helper, the per-mode diff that decides what to
 * actually send for user-level settings, and the saved/cleared-flash
 * timer pair factory. The SFC keeps the form bindings, store calls, and
 * the timed-flash ref lifecycle.
 */
import type { ToolSchema } from '@/composables/useToolSettings'

/** Whether the given key in a tool's settings schema is a password field. */
export function isPasswordField(tool: ToolSchema, key: string): boolean {
  return tool.settings_schema.find((f) => f.key === key)?.type === 'password' || false
}

/** Display value for the read-only "current configuration" panel. */
export function displayValue(
  tool: ToolSchema,
  key: string,
  value: string,
): string {
  if (value === '' || value === null) return '—'
  if (isPasswordField(tool, key)) return '••••••••'
  return value
}

/**
 * Diff user-level settings against the global defaults, returning only the
 * fields that differ. Empty values are kept (they explicitly clear the
 * override). Used by the `mode === 'user'` save path.
 */
export function diffFromGlobalDefaults(
  settings: Record<string, string>,
  globalDefaults: Record<string, string> | undefined,
): Record<string, string> {
  const toSave: Record<string, string> = {}
  for (const [key, value] of Object.entries(settings)) {
    const globalVal = globalDefaults?.[key] ?? ''
    if (value !== globalVal) {
      toSave[key] = value
    }
  }
  return toSave
}

/** Whether the server has any non-empty values for this tool. */
export function hasExistingSettings(serverSettings: Record<string, string>): boolean {
  return Object.values(serverSettings).some((v) => v !== '' && v !== null)
}

/** Count of non-empty values (drives the "N saved" badge in the panel). */
export function countNonEmptySettings(serverSettings: Record<string, string>): number {
  return Object.values(serverSettings).filter((v) => v !== '' && v !== null).length
}

/** Subset of `settings_schema` flagged as LLM-exposed. */
export function llmExposedFields(tool: ToolSchema) {
  return tool.settings_schema.filter((f) => f.expose_to_llm)
}

/** Resolve the panel's mode (defaults to "global" when prop is omitted). */
export function resolveMode(mode: 'global' | 'user' | undefined): 'global' | 'user' {
  return mode ?? 'global'
}
