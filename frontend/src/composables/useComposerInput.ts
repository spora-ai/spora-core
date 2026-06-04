/**
 * useComposerInput — pure helpers for the ComposerInput component.
 *
 * Owns:
 *  - The system-variable substitution pass for prompt templates
 *    (current_date / current_time / current_datetime)
 *  - The textarea auto-resize math
 *  - A Cmd+Enter / Ctrl+Enter keystroke detector
 */

export const COMPOSER_PLACEHOLDER_REGEX = /\{\{(\w+)(?::([^}]*))?\}\}/g

export interface SystemVariables {
  current_date: string
  current_time: string
  current_datetime: string
}

/** Snapshot the current time as the three ISO-style system variables. */
export function snapshotSystemVariables(now: Date = new Date()): SystemVariables {
  return {
    current_date: now.toISOString().split('T')[0],
    current_time: now.toTimeString().slice(0, 5),
    current_datetime: now.toISOString().slice(0, 16),
  }
}

export interface TemplateVariable {
  key: string
  default_value?: string | null
}

/**
 * Replace every `{{var}}` / `{{var:default}}` placeholder in `text`. Resolution
 * order: explicit system vars → template variable default → inline default in
 * the placeholder → keep the original token.
 */
export function substituteTemplateVariables(
  text: string,
  systemVars: SystemVariables,
  variables?: TemplateVariable[] | null,
): string {
  return text.replace(
    COMPOSER_PLACEHOLDER_REGEX,
    (match: string, key: string, defaultVal?: string) => {
      if (systemVars[key as keyof SystemVariables] !== undefined) {
        return systemVars[key as keyof SystemVariables]
      }
      const v = variables?.find((v) => v.key === key)
      if (v?.default_value) return v.default_value
      if (defaultVal !== undefined) return defaultVal
      return match
    },
  )
}

/** Build the prompt text from a template (substituted with system vars). */
export function buildPromptFromTemplate(
  template: string,
  variables?: TemplateVariable[] | null,
  now: Date = new Date(),
): string {
  return substituteTemplateVariables(template, snapshotSystemVariables(now), variables)
}

/** Compute the new height for the auto-expanding textarea (capped at 300). */
export function computeTextareaHeight(scrollHeight: number, max = 300): number {
  return Math.min(scrollHeight, max)
}

/** Whether the event should trigger submission (Cmd+Enter / Ctrl+Enter). */
export function isSubmitKeystroke(e: KeyboardEvent): boolean {
  return e.key === 'Enter' && (e.metaKey || e.ctrlKey)
}
