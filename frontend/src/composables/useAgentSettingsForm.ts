/**
 * useAgentSettingsForm — pure helpers for AgentSettingsPage.
 *
 * Owns the identity/LLM form initialization, payload building, and label
 * formatting. Tool-category grouping lives in `@/utils/toolCategories` since
 * it's a pure utility shared with other features.
 */

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
