import { describe, it, expect } from 'vitest'
import {
  formatToolArguments,
  parseArguments,
  isFlatArguments,
  buildArgumentsFromFields,
} from '@/composables/useToolArgumentFormatter'

describe('formatToolArguments', () => {
  it('respects parameterOrder when provided', () => {
    const args = { body: 'msg', action: 'send', to: 'a@b.c', subject: 'hi' }
    const order = ['action', 'to', 'subject', 'body']

    const fields = formatToolArguments(args, { parameterOrder: order })

    expect(fields.map(f => f.key)).toEqual(['action', 'to', 'subject', 'body'])
  })

  it('sorts unknown keys to the end of the parameterOrder list', () => {
    const args = { extra1: 'x', subject: 'hi', extra2: 'y', action: 'send' }
    const order = ['action', 'subject']

    const fields = formatToolArguments(args, { parameterOrder: order })

    expect(fields.map(f => f.key)).toEqual(['action', 'subject', 'extra1', 'extra2'])
  })

  it('preserves the legacy important-first alphabetical sort when no parameterOrder is given', () => {
    // 'body' and 'to' are "important"; the others sort alphabetically below them.
    const args = { zzz: 'last', to: 'a@b.c', body: 'msg', aaa: 'first' }

    const fields = formatToolArguments(args)
    const keys = fields.map(f => f.key)

    // Important fields first (alphabetically among themselves)
    expect(keys.indexOf('body')).toBeLessThan(keys.indexOf('aaa'))
    expect(keys.indexOf('to')).toBeLessThan(keys.indexOf('aaa'))
    // Non-important fields alphabetical
    expect(keys.indexOf('aaa')).toBeLessThan(keys.indexOf('zzz'))
  })

  it('returns an empty array for null or non-object input', () => {
    expect(formatToolArguments(null)).toEqual([])
    expect(formatToolArguments(null, { parameterOrder: ['x'] })).toEqual([])
  })

  it('infers per-field format from the key name (still works with parameterOrder)', () => {
    const fields = formatToolArguments(
      { body: 'lorem', to: 'a@b.c', url: 'https://x' },
      { parameterOrder: ['url', 'to', 'body'] },
    )

    expect(fields[0].format).toBe('url')
    expect(fields[1].format).toBe('email')
    expect(fields[2].format).toBe('multiline')
  })

  it('treats empty parameterOrder as "no order" and falls back to legacy sort', () => {
    const args = { to: 'a@b.c', aaa: 'x' }

    const withEmpty = formatToolArguments(args, { parameterOrder: [] })
    const legacy = formatToolArguments(args)

    expect(withEmpty.map(f => f.key)).toEqual(legacy.map(f => f.key))
  })
})

describe('parseArguments', () => {
  it('returns the object as-is when given a plain object', () => {
    const obj = { foo: 'bar' }
    expect(parseArguments(obj)).toBe(obj)
  })

  it('parses a JSON string into an object', () => {
    expect(parseArguments('{"foo":"bar"}')).toEqual({ foo: 'bar' })
  })

  it('returns null for null/undefined/empty input', () => {
    expect(parseArguments(null)).toBe(null)
    expect(parseArguments(undefined)).toBe(null)
    expect(parseArguments('')).toBe(null)
    expect(parseArguments(0)).toBe(null)
  })

  it('returns null for invalid JSON strings', () => {
    expect(parseArguments('not json')).toBe(null)
  })

  it('returns null for JSON that parses to a non-object scalar (string/number)', () => {
    expect(parseArguments('"hello"')).toBe(null)
    expect(parseArguments('42')).toBe(null)
  })

  it('parses JSON arrays (callers must handle the array shape themselves)', () => {
    // parseArguments only rejects scalars — arrays pass through because
    // typeof [] === 'object'. The cast to Record<string, unknown> is the
    // caller's responsibility (use isFlatArguments to validate).
    expect(parseArguments('[1,2,3]')).toEqual([1, 2, 3])
  })
})

describe('isFlatArguments', () => {
  it('returns true for an object whose values are all primitives', () => {
    expect(isFlatArguments({ a: 'x', b: 1, c: true, d: null })).toBe(true)
  })

  it('returns false when any value is a nested object or array', () => {
    expect(isFlatArguments({ a: { nested: true } })).toBe(false)
    expect(isFlatArguments({ a: [1, 2] })).toBe(false)
  })

  it('returns false for null and non-objects', () => {
    expect(isFlatArguments(null)).toBe(false)
    expect(isFlatArguments(undefined)).toBe(false)
    expect(isFlatArguments('string')).toBe(false)
  })
})

describe('buildArgumentsFromFields', () => {
  it('reconstructs the original object from formatted fields', () => {
    const fields = formatToolArguments({ to: 'a@b.c', body: 'msg' })
    const rebuilt = buildArgumentsFromFields(fields)
    expect(rebuilt).toEqual({ to: 'a@b.c', body: 'msg' })
  })

  it('returns an empty object for empty input', () => {
    expect(buildArgumentsFromFields([])).toEqual({})
  })

  it('preserves the value type (number, boolean, null)', () => {
    const fields = formatToolArguments({ count: 3, enabled: true, note: null })
    const rebuilt = buildArgumentsFromFields(fields)
    expect(rebuilt).toEqual({ count: 3, enabled: true, note: null })
  })
})
