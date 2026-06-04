export type FieldFormat = 'multiline' | 'email' | 'url' | 'sensitive' | 'badge' | 'boolean' | 'text'

export interface FormattedField {
  key: string
  label: string
  value: unknown
  displayValue: string
  format: FieldFormat
  type: string
  isImportant: boolean
}

const FORMAT_PATTERNS: Array<{ pattern: RegExp; format: FieldFormat }> = [
  { pattern: /^(body|message|content|text)$/i, format: 'multiline' },
  { pattern: /^(url|link|href|src)$/i, format: 'url' },
  { pattern: /^(to|email|from|cc|bcc|reply_to)$/i, format: 'email' },
  { pattern: /^(password|secret|api_key|token|credential)$/i, format: 'sensitive' },
  { pattern: /^(action|status|type|operation|method)$/i, format: 'badge' },
]

const IMPORTANT_PATTERNS = [
  /^(body|message|content|text|subject|title)$/i,
  /^(to|email|from|cc|bcc|reply_to)$/i,
  /^(subject|title)$/i,
]

function inferFormat(key: string, value: unknown): FieldFormat {
  for (const { pattern, format } of FORMAT_PATTERNS) {
    if (pattern.test(key)) return format
  }
  if (typeof value === 'boolean') return 'boolean'
  return 'text'
}

function isImportantField(key: string): boolean {
  return IMPORTANT_PATTERNS.some(p => p.test(key))
}

function extractLabel(key: string, _value?: unknown): string {
  // For known fields, use a readable label
  const labels: Record<string, string> = {
    to: 'To',
    email: 'Email',
    from: 'From',
    subject: 'Subject',
    body: 'Body',
    message: 'Message',
    text: 'Text',
    url: 'URL',
    link: 'Link',
    action: 'Action',
    status: 'Status',
    type: 'Type',
    limit: 'Limit',
    query: 'Query',
    id: 'ID',
    name: 'Name',
  }
  if (labels[key.toLowerCase()]) return labels[key.toLowerCase()]
  // Fallback: capitalize first letter
  return key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ')
}

function maskSensitive(value: string): string {
  if (typeof value !== 'string' || value.length <= 4) return '••••••••'
  return value.slice(0, 2) + '••••••••' + value.slice(-2)
}

/**
 * Stringify a value of unknown type. Plain objects/arrays are JSON-serialised so
 * we never render the useless "[object Object]" default — primitives keep their
 * existing String() behavior so tests and downstream consumers don't change.
 */
function stringifyValue(value: unknown): string {
  if (typeof value === 'object' && value !== null) {
    try {
      return JSON.stringify(value)
    } catch {
      return Object.prototype.toString.call(value)
    }
  }
  return String(value)
}

function formatDisplayValue(value: unknown, format: FieldFormat): string {
  if (value === null || value === undefined) return '—'
  if (format === 'sensitive') return maskSensitive(stringifyValue(value))
  if (format === 'badge') return stringifyValue(value).replace(/_/g, ' ')
  if (format === 'boolean') return stringifyValue(value)
  return stringifyValue(value)
}

/**
 * Parse arguments that may arrive as a JSON string or already as an object.
 * This handles double-escaped JSON from the backend.
 */
export function parseArguments(args: unknown): Record<string, unknown> | null {
  if (!args) return null
  if (typeof args === 'object') return args as Record<string, unknown>
  if (typeof args === 'string') {
    try {
      const parsed = JSON.parse(args)
      if (typeof parsed === 'object' && parsed !== null) {
        return parsed as Record<string, unknown>
      }
    } catch {
      // Not valid JSON, return null
    }
  }
  return null
}

export function isFlatArguments(args: Record<string, unknown> | null): boolean {
  if (!args || typeof args !== 'object') return false
  return Object.values(args).every(v =>
    v === null ||
    v === undefined ||
    typeof v === 'string' ||
    typeof v === 'number' ||
    typeof v === 'boolean'
  )
}

export interface ToolArgumentFormatterOptions {
  toolName?: string
  operation?: string | null
  /**
   * Canonical parameter order from the tool's #[ToolParameter] declarations,
   * surfaced to the frontend via `ToolCall.parameter_schema.properties` keys.
   * When provided, fields render in this order regardless of LLM emission order.
   * Keys not in the list sort to the end (preserves the existing object-key
   * iteration order for unknown extras). When omitted, falls back to the
   * legacy important-first-alphabetical sort for backward compatibility.
   */
  parameterOrder?: string[]
}

export function formatToolArguments(
  args: Record<string, unknown> | null,
  options?: ToolArgumentFormatterOptions,
): FormattedField[] {
  if (!args || typeof args !== 'object') return []

  const fields = Object.entries(args).map(([key, value]) => {
    const format = inferFormat(key, value)
    return {
      key,
      label: extractLabel(key, value),
      value,
      displayValue: formatDisplayValue(value, format),
      format,
      type: typeof value,
      isImportant: isImportantField(key),
    }
  })

  const parameterOrder = options?.parameterOrder
  if (parameterOrder && parameterOrder.length > 0) {
    // Build the index map once; unknown keys sort to the end in their original
    // object-iteration order via a stable sort fallback.
    const indexOf = new Map<string, number>()
    parameterOrder.forEach((k, i) => indexOf.set(k, i))
    return fields
      .map((field, originalIndex) => ({ field, originalIndex }))
      .sort((a, b) => {
        const ia = indexOf.get(a.field.key) ?? Infinity
        const ib = indexOf.get(b.field.key) ?? Infinity
        if (ia !== ib) return ia - ib
        return a.originalIndex - b.originalIndex
      })
      .map(({ field }) => field)
  }

  return fields.sort((a, b) => {
    if (a.isImportant !== b.isImportant) return a.isImportant ? -1 : 1
    return a.key.localeCompare(b.key)
  })
}

export function buildArgumentsFromFields(
  fields: FormattedField[],
): Record<string, unknown> {
  const result: Record<string, unknown> = {}
  for (const field of fields) {
    result[field.key] = field.value
  }
  return result
}
