/**
 * lib/utils — small helpers shared across the app.
 */
import { describe, it, expect } from 'vitest'
import { cn } from '@/lib/utils'

describe('cn', () => {
  it('joins class names', () => {
    expect(cn('foo', 'bar')).toBe('foo bar')
  })

  it('filters out falsy values', () => {
    expect(cn('foo', false, null, undefined, '', 'bar')).toBe('foo bar')
  })

  it('handles a single class', () => {
    expect(cn('foo')).toBe('foo')
  })

  it('returns empty string when given no inputs', () => {
    expect(cn()).toBe('')
  })
})
