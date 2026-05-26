import type { ToolSchema } from '@/composables/useToolSettings'

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
 */
export function initFormFromSettingsWithSource(
  settingsWithSource: Record<string, { value: unknown; source: string }>,
): Record<string, string> {
  const form: Record<string, string> = {}

  for (const [key, item] of Object.entries(settingsWithSource)) {
    if (item.source === 'agent') {
      form[key] = String(item.value ?? '')
    }
  }

  return form
}
