/**
 * usePromptTemplateVars — pure helpers for the PromptTemplateDialog.
 *
 * Owns the `{{var}}` / `{{var:default}}` placeholder parser and the
 * payload builder for create/update of prompt templates.
 */

export const PROMPT_TEMPLATE_SYSTEM_VARS = [
  'current_time',
  'current_date',
  'current_datetime',
] as const

export interface DetectedVariable {
  key: string
  defaultValue: string
  isSystem: boolean
}

/** Parse a template body for `{{var}}` and `{{var:default}}` tokens. */
export function detectVariables(template: string): DetectedVariable[] {
  const seen = new Set<string>()
  const vars: DetectedVariable[] = []
  const re = /\{\{(\w+)(?::([^}]*))?\}\}/g
  let m: RegExpExecArray | null
  while ((m = re.exec(template)) !== null) {
    const key = m[1]
    if (seen.has(key)) continue
    seen.add(key)
    vars.push({
      key,
      defaultValue: m[2] ?? '',
      isSystem: (PROMPT_TEMPLATE_SYSTEM_VARS as readonly string[]).includes(key),
    })
  }
  return vars
}

export interface TemplateVariablePayload {
  key: string
  label: string
  default_value?: string
}

/**
 * Build the `variables` array sent to the backend when saving a template.
 * System variables are auto-filled server-side and never sent; user
 * variables get the value from the dialog (or the parsed default), with
 * `default_value` omitted when the user left it blank.
 */
export function buildVariablesPayload(
  detectedVars: DetectedVariable[],
  values: Record<string, string>,
): TemplateVariablePayload[] {
  return detectedVars
    .filter((v) => !v.isSystem)
    .map((v) => ({
      key: v.key,
      label: v.key,
      default_value: values[v.key] || v.defaultValue || undefined,
    }))
}

/** Build the full create/update payload for a prompt template. */
export function buildTemplatePayload(input: {
  name: string
  promptTemplate: string
  variables: DetectedVariable[]
  values: Record<string, string>
}): {
  name: string
  prompt_template: string
  variables: TemplateVariablePayload[]
} {
  return {
    name: input.name.trim(),
    prompt_template: input.promptTemplate,
    variables: buildVariablesPayload(input.variables, input.values),
  }
}

/** Seed the values map with the current system-variable snapshot. */
export function seedSystemVariableValues(now: Date = new Date()): Record<string, string> {
  return {
    current_date: now.toISOString().split('T')[0],
    current_time: now.toTimeString().slice(0, 5),
    current_datetime: now.toISOString().slice(0, 16),
  }
}
