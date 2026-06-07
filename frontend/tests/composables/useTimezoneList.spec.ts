import { describe, it, expect } from 'vitest'
import {
  partitionTimezones,
  defaultTimezone,
  buildTimezoneList,
} from '@/composables/useTimezoneList'

describe('useTimezoneList', () => {
  describe('partitionTimezones', () => {
    it('splits common vs rest and sorts each', () => {
      const all = ['Asia/Tokyo', 'Europe/London', 'America/New_York']
      const common = new Set(['Europe/London'])
      const { common: c, rest } = partitionTimezones(all, common)
      expect(c).toEqual(['Europe/London'])
      expect(rest).toEqual(['America/New_York', 'Asia/Tokyo'])
    })

    it('returns empty common when no overlap', () => {
      const all = ['Asia/Tokyo', 'Europe/London']
      const { common, rest } = partitionTimezones(all, new Set())
      expect(common).toEqual([])
      expect(rest).toEqual(['Asia/Tokyo', 'Europe/London'])
    })

    it('returns all in common when every value is in the set', () => {
      const all = ['Europe/Berlin', 'Europe/London']
      const { common, rest } = partitionTimezones(all, new Set(all))
      expect(common).toEqual(['Europe/Berlin', 'Europe/London'])
      expect(rest).toEqual([])
    })
  })

  describe('defaultTimezone', () => {
    it('returns a non-empty timezone string', () => {
      const tz = defaultTimezone()
      expect(typeof tz).toBe('string')
      expect(tz.length).toBeGreaterThan(0)
    })
  })

  describe('buildTimezoneList', () => {
    it('builds a flat list with common first', () => {
      const all = ['Asia/Tokyo', 'Europe/London', 'America/New_York']
      const common = new Set(['Europe/London'])
      const list = buildTimezoneList(all, common)
      expect(list.map(x => x.value)).toEqual(['Europe/London', 'America/New_York', 'Asia/Tokyo'])
    })

    it('returns value+label pairs', () => {
      const list = buildTimezoneList(['UTC'], new Set())
      expect(list[0]).toEqual({ value: 'UTC', label: 'UTC' })
    })
  })
})
