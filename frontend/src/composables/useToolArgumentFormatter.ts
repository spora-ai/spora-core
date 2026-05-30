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

function formatDisplayValue(value: unknown, format: FieldFormat): string {
  if (value === null || value === undefined) return '—'
  if (format === 'sensitive') return maskSensitive(String(value))
  if (format === 'badge') return String(value).replace(/_/g, ' ')
  if (format === 'boolean') return String(value)
  return String(value)
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
}

export function formatToolArguments(
  args: Record<string, unknown> | null,
  _options?: ToolArgumentFormatterOptions,
): FormattedField[] {
  if (!args || typeof args !== 'object') return []

  return Object.entries(args)
    .map(([key, value]) => {
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
    .sort((a, b) => {
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
