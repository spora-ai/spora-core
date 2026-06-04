/**
 * useToolArgumentsEditor — pure helpers for ToolArgumentsEditor.
 */
import { describe, it, expect } from 'vitest'
import {
  getParameterOrder,
  emitArgumentsJson,
  findFieldByKey,
  updateFieldValue,
  toggleSensitiveFlag,
  clearSensitiveFlags,
  isUrl,
  isEmail,
} from '@/composables/useToolArgumentsEditor'
import type { FormattedField } from '@/composables/useToolArgumentFormatter'

const makeField = (key: string, value: unknown): FormattedField => ({
  key,
  label: key,
  value,
  displayValue: String(value),
  format: 'text',
  type: typeof value,
  isImportant: false,
})

describe('useToolArgumentsEditor helpers', () => {
  describe('getParameterOrder', () => {
    it('returns the keys of properties', () => {
      expect(getParameterOrder({ properties: { a: {}, b: {} } })).toEqual(['a', 'b'])
    })

    it('returns [] for null/undefined/missing properties', () => {
      expect(getParameterOrder(null)).toEqual([])
      expect(getParameterOrder(undefined)).toEqual([])
      expect(getParameterOrder({})).toEqual([])
      expect(getParameterOrder({ properties: null })).toEqual([])
    })
  })

  describe('emitArgumentsJson', () => {
    it('serializes fields by key/value', () => {
      const fields = [makeField('to', 'a@b.c'), makeField('body', 'msg')]
      expect(emitArgumentsJson(fields)).toBe('{"to":"a@b.c","body":"msg"}')
    })

    it('handles an empty field list', () => {
      expect(emitArgumentsJson([])).toBe('{}')
    })
  })

  describe('findFieldByKey', () => {
    it('returns the matching field', () => {
      const fields = [makeField('to', 'a@b.c'), makeField('body', 'msg')]
      expect(findFieldByKey(fields, 'body')?.value).toBe('msg')
    })

    it('returns undefined when missing', () => {
      expect(findFieldByKey([], 'missing')).toBeUndefined()
    })
  })

  describe('updateFieldValue', () => {
    it('updates the matching field and leaves others alone', () => {
      const fields = [makeField('to', 'a@b.c'), makeField('body', 'msg')]
      const out = updateFieldValue(fields, 'body', 'new')
      expect(out[0].value).toBe('a@b.c')
      expect(out[1].value).toBe('new')
    })

    it('returns a new array (does not mutate)', () => {
      const fields = [makeField('to', 'a@b.c')]
      const out = updateFieldValue(fields, 'to', 'x')
      expect(out).not.toBe(fields)
    })
  })

  describe('toggleSensitiveFlag', () => {
    it('flips the flag and returns a new object', () => {
      const flags = { a: true, b: false }
      const out = toggleSensitiveFlag(flags, 'b')
      expect(out.b).toBe(true)
      expect(out.a).toBe(true)
      expect(out).not.toBe(flags)
    })

    it('introduces a new key as true when absent', () => {
      expect(toggleSensitiveFlag({}, 'x')).toEqual({ x: true })
    })
  })

  describe('clearSensitiveFlags', () => {
    it('returns an empty map', () => {
      expect(clearSensitiveFlags()).toEqual({})
    })
  })

  describe('isUrl', () => {
    it('matches http and https', () => {
      expect(isUrl('http://x')).toBe(true)
      expect(isUrl('https://x')).toBe(true)
    })

    it('rejects non-strings and other protocols', () => {
      expect(isUrl(123)).toBe(false)
      expect(isUrl('ftp://x')).toBe(false)
      expect(isUrl(null)).toBe(false)
    })
  })

  describe('isEmail', () => {
    it('matches a basic address', () => {
      expect(isEmail('a@b.co')).toBe(true)
    })

    it('rejects invalid addresses', () => {
      expect(isEmail('not-an-email')).toBe(false)
      expect(isEmail('a@b')).toBe(false)
      expect(isEmail(null)).toBe(false)
    })
  })
})
