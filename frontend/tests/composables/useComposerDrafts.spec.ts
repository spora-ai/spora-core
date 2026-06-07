import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { loadComposerDrafts, saveComposerDrafts } from '@/composables/useComposerDrafts'

const sessionStorageStore: Record<string, string> = {}

beforeEach(() => {
  for (const k in sessionStorageStore) delete sessionStorageStore[k]
  Object.defineProperty(globalThis, 'sessionStorage', {
    value: {
      getItem: (k: string) => sessionStorageStore[k] ?? null,
      setItem: (k: string, v: string) => { sessionStorageStore[k] = v },
      removeItem: (k: string) => { delete sessionStorageStore[k] },
    },
    configurable: true,
  })
})

describe('useComposerDrafts', () => {
  describe('loadComposerDrafts', () => {
    it('returns empty map when storage is empty', () => {
      expect(loadComposerDrafts()).toEqual({})
    })

    it('parses a stored JSON object', () => {
      sessionStorageStore['spora:composer-drafts'] = JSON.stringify({ 1: { promptText: 'hello' } })
      expect(loadComposerDrafts()).toEqual({ 1: { promptText: 'hello' } })
    })

    it('returns empty map on corrupt JSON', () => {
      sessionStorageStore['spora:composer-drafts'] = '{not valid json'
      expect(loadComposerDrafts()).toEqual({})
    })

    it('returns empty map when sessionStorage throws (private mode)', () => {
      Object.defineProperty(globalThis, 'sessionStorage', {
        value: { getItem: () => { throw new Error('SecurityError') } },
        configurable: true,
      })
      expect(loadComposerDrafts()).toEqual({})
    })
  })

  describe('saveComposerDrafts', () => {
    it('persists the map as JSON', () => {
      saveComposerDrafts({ 7: { promptText: 'draft' } })
      expect(sessionStorageStore['spora:composer-drafts']).toBe(JSON.stringify({ 7: { promptText: 'draft' } }))
    })

    it('silently no-ops when setItem throws', () => {
      Object.defineProperty(globalThis, 'sessionStorage', {
        value: { setItem: () => { throw new Error('QuotaExceeded') } },
        configurable: true,
      })
      // Should not throw.
      expect(() => saveComposerDrafts({ 1: { promptText: 'x' } })).not.toThrow()
    })
  })
})
