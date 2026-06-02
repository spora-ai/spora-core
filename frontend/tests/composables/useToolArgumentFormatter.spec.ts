import { describe, it, expect } from 'vitest'
import { formatToolArguments } from '@/composables/useToolArgumentFormatter'

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
