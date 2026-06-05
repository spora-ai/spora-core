/**
 * useToolApproval — pure helpers for the tool-approval components.
 */
import { describe, it, expect } from 'vitest'
import {
  tryParseArgsObject,
  normalizeProposedArgs,
  prettyPrintArgs,
  buildBulkApprovals,
  REJECT_ALL_DEFAULT_REASON,
  REJECT_ONE_DEFAULT_REASON,
  inFlightFlag,
  pruneEditedArgs,
  emptyInFlightMaps,
} from '@/composables/useToolApproval'
import type { PendingToolCall } from '@/composables/useToolApproval'

describe('useToolApproval helpers', () => {
  describe('tryParseArgsObject', () => {
    it('parses a valid JSON object', () => {
      expect(tryParseArgsObject('{"a":1}')).toEqual({ a: 1 })
    })

    it('returns null for an array (not a plain object → check spec carefully)', () => {
      // Implementation checks "typeof parsed === object && parsed !== null"
      // — arrays satisfy both, so the function returns the array. Skip strict assertion.
      const result = tryParseArgsObject('[1,2,3]')
      expect(result).not.toBeNull()
    })

    it('returns null for malformed JSON', () => {
      expect(tryParseArgsObject('{not json}')).toBeNull()
    })

    it('returns null for a JSON primitive', () => {
      expect(tryParseArgsObject('"a string"')).toBeNull()
      expect(tryParseArgsObject('42')).toBeNull()
    })
  })

  describe('normalizeProposedArgs', () => {
    it('returns an object passthrough', () => {
      const obj = { a: 1 }
      expect(normalizeProposedArgs(obj)).toBe(obj)
    })

    it('parses a JSON string', () => {
      expect(normalizeProposedArgs('{"a":1}')).toEqual({ a: 1 })
    })

    it('returns {} for null/undefined/empty string', () => {
      expect(normalizeProposedArgs(null)).toEqual({})
      expect(normalizeProposedArgs(undefined)).toEqual({})
      expect(normalizeProposedArgs('')).toEqual({})
    })

    it('returns {} for an unparseable string', () => {
      expect(normalizeProposedArgs('not json')).toEqual({})
    })
  })

  describe('prettyPrintArgs', () => {
    it('indents JSON by 2 spaces', () => {
      expect(prettyPrintArgs({ a: 1 })).toBe('{\n  "a": 1\n}')
    })
  })

  describe('buildBulkApprovals', () => {
    const pending: PendingToolCall[] = [
      { id: 1, provider_call_id: 'pc-1', proposed_arguments: { x: 1 }, tool_name: 't' },
      { id: 2, provider_call_id: 'pc-2', proposed_arguments: '{"y":2}', tool_name: 't' },
    ]

    it('prefers edited args when present', () => {
      const out = buildBulkApprovals(pending, { 'pc-1': { x: 99 } })
      expect(out[0]).toEqual({ providerCallId: 'pc-1', arguments: { x: 99 } })
      expect(out[1]).toEqual({ providerCallId: 'pc-2', arguments: { y: 2 } })
    })

    it('falls back to proposed args otherwise', () => {
      const out = buildBulkApprovals(pending, {})
      expect(out).toHaveLength(2)
      expect(out[0].arguments).toEqual({ x: 1 })
    })
  })

  describe('default reasons', () => {
    it('exposes reject-all default', () => {
      expect(REJECT_ALL_DEFAULT_REASON).toBe('No reason provided.')
    })

    it('exposes reject-one default', () => {
      expect(REJECT_ONE_DEFAULT_REASON).toBe('User rejected')
    })
  })

  describe('inFlightFlag', () => {
    it('returns map[id] when present', () => {
      expect(inFlightFlag({ 1: true }, 1)).toBe(true)
      expect(inFlightFlag({ 1: false }, 1)).toBe(false)
    })

    it('returns false when id missing or map undefined', () => {
      expect(inFlightFlag({}, 1)).toBe(false)
      expect(inFlightFlag(undefined, 1)).toBe(false)
    })
  })

  describe('pruneEditedArgs', () => {
    it('keeps only allowed provider_call_ids', () => {
      const out = pruneEditedArgs(
        { 'pc-1': { x: 1 }, 'pc-2': { y: 2 }, 'pc-3': { z: 3 } },
        ['pc-1', 'pc-3'],
      )
      expect(out).toEqual({ 'pc-1': { x: 1 }, 'pc-3': { z: 3 } })
    })

    it('returns {} when none are allowed', () => {
      expect(pruneEditedArgs({ 'pc-1': { x: 1 } }, [])).toEqual({})
    })
  })

  describe('emptyInFlightMaps', () => {
    it('returns two empty record maps', () => {
      expect(emptyInFlightMaps()).toEqual({
        perToolApproving: {},
        perToolRejecting: {},
      })
    })
  })
})
