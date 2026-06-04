/**
 * useToolArgumentsEditor — pure helpers for the ToolArgumentsEditor component.
 *
 * Owns the URL/email regex tests, the JSON-arg emit pipeline, and the
 * (server-provided) parameter-order extraction. The SFC keeps the
 * textarea bindings, the show/hide per-field state, and the template
 * branches for the various FieldFormat types.
 */
import { buildArgumentsFromFields, type FormattedField } from '@/composables/useToolArgumentFormatter'

export interface ParameterSchemaLike {
  properties?: Record<string, unknown> | null
}

/** Canonical declaration order from the backend parameter schema, or []. */
export function getParameterOrder(schema: ParameterSchemaLike | null | undefined): string[] {
  return schema?.properties ? Object.keys(schema.properties) : []
}

/** Build the JSON string that the parent saves as `update:arguments`. */
export function emitArgumentsJson(fields: FormattedField[]): string {
  return JSON.stringify(buildArgumentsFromFields(fields))
}

/** Find a field in the local list by key. */
export function findFieldByKey(
  fields: FormattedField[],
  key: string,
): FormattedField | undefined {
  return fields.find((f) => f.key === key)
}

/** Update a single field's value and return the new field array. */
export function updateFieldValue(
  fields: FormattedField[],
  key: string,
  value: unknown,
): FormattedField[] {
  return fields.map((f) => (f.key === key ? { ...f, value } : f))
}

/** Toggle a field's "show plain text" flag in the show-sensitive map. */
export function toggleSensitiveFlag(
  flags: Record<string, boolean>,
  key: string,
): Record<string, boolean> {
  return { ...flags, [key]: !flags[key] }
}

/** Reset the show-sensitive flags (e.g. when args change). */
export function clearSensitiveFlags(): Record<string, boolean> {
  return {}
}

/** Whether the value looks like an http(s) URL. */
export function isUrl(value: unknown): boolean {
  return typeof value === 'string' && /^https?:\/\//.test(value)
}

// Bounded quantifiers + 254-char pre-check prevent the ReDoS that
// `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` allowed via unbounded backtracking.
const MAX_EMAIL_LENGTH = 254
const EMAIL_RE = /^[^\s@]{1,64}@[^\s@.]{1,64}(?:\.[^\s@.]{1,64})+$/

export function isEmail(value: unknown): boolean {
  if (typeof value !== 'string') return false
  if (value.length === 0 || value.length > MAX_EMAIL_LENGTH) return false
  return EMAIL_RE.test(value)
}
