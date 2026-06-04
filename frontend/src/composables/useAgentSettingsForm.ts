/**
 * useAgentSettingsForm — pure helpers for AgentSettingsPage.
 *
 * Splits tools into categories, formats tool group labels, and exposes the
 * identity/LLM/tool-toggle flow as pure functions so the SFC can keep only
 * template wiring and store dispatch.
 */
import type { ToolSchema } from '@/composables/useToolSettings'

export interface IdentityForm {
  name: string
  description: string
  system_prompt: string
  max_steps: number
  allow_continuation: boolean
  retry_after_minutes: number
  max_retries: number
}

export interface LlmSettingsForm {
  llm_driver_config_id: number | null
}

/** Capitalize a category key (e.g. "communication" → "Communication"). */
export function categoryLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

/** Group tools by their `category` field, defaulting to "general". */
export function groupToolsByCategory(
  tools: ToolSchema[],
): Record<string, ToolSchema[]> {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of tools) {
    const cat = (tool as unknown as { category?: string }).category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
}

/** Return category keys sorted alphabetically by their human label. */
export function sortCategoryKeys(categories: Record<string, unknown>): string[] {
  return Object.keys(categories).sort((a, b) =>
    categoryLabel(a).localeCompare(categoryLabel(b)),
  )
}

/** Format the human label for an LLM config row in the dropdown. */
export function formatLlmConfigLabel(config: {
  name: string
  driver_display_name: string
  is_global: boolean
}): string {
  return config.is_global
    ? `${config.name} (${config.driver_display_name}) — Global`
    : `${config.name} (${config.driver_display_name})`
}

/** Build the identity form's initial values from a backend Agent resource. */
export function buildInitialIdentityForm(agent: {
  name: string
  description?: string | null
  system_prompt?: string | null
  max_steps?: number | null
  allow_continuation?: boolean | null
  retry_after_minutes?: number | null
  max_retries?: number | null
}): IdentityForm {
  return {
    name: agent.name,
    description: agent.description ?? '',
    system_prompt: agent.system_prompt ?? '',
    max_steps: agent.max_steps ?? 10,
    allow_continuation: agent.allow_continuation !== false,
    retry_after_minutes: agent.retry_after_minutes ?? 0,
    max_retries: agent.max_retries ?? 0,
  }
}

/** Build the LLM settings form's initial values. */
export function buildInitialLlmSettings(agent: {
  llm_driver_config_id?: number | null
}): LlmSettingsForm {
  return {
    llm_driver_config_id: agent.llm_driver_config_id ?? null,
  }
}

/** Convert the identity form into a PATCH /agents/{id} payload. */
export function buildIdentityPayload(form: IdentityForm): Record<string, unknown> {
  return {
    name: form.name,
    description: form.description || null,
    system_prompt: form.system_prompt || null,
    max_steps: form.max_steps,
    allow_continuation: form.allow_continuation,
    retry_after_minutes: form.retry_after_minutes,
    max_retries: form.max_retries,
  }
}

/** Convert the LLM settings form into a PATCH /agents/{id} payload. */
export function buildLlmSettingsPayload(form: LlmSettingsForm): Record<string, unknown> {
  return {
    llm_driver_config_id: form.llm_driver_config_id,
  }
}
