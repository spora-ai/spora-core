/**
 * useProfileSettings — pure helpers for ProfileSettingsPage.
 */
import { describe, it, expect } from 'vitest'
import {
  emptyProfile,
  emptyLocationForm,
  validateLocationForm,
  firstLocationError,
} from '@/composables/useProfileSettings'

describe('useProfileSettings helpers', () => {
  describe('emptyProfile', () => {
    it('returns a profile with all fields null', () => {
      expect(emptyProfile()).toEqual({
        name: null,
        date_of_birth: null,
        about_me: null,
        height_cm: null,
        weight_kg: null,
      })
    })
  })

  describe('emptyLocationForm', () => {
    it('returns an empty location form', () => {
      expect(emptyLocationForm()).toEqual({
        name: '',
        address: '',
        is_default: false,
      })
    })
  })

  describe('validateLocationForm', () => {
    it('returns errors when name and address are missing', () => {
      const errors = validateLocationForm({ name: '', address: '' })
      expect(errors.name).toBe('Name is required.')
      expect(errors.address).toBe('Address is required.')
    })

    it('errors for whitespace-only values', () => {
      const errors = validateLocationForm({ name: '   ', address: '   ' })
      expect(errors.name).toBe('Name is required.')
      expect(errors.address).toBe('Address is required.')
    })

    it('returns no errors when both fields are filled', () => {
      expect(validateLocationForm({ name: 'Home', address: '1 Main St' })).toEqual({})
    })

    it('only flags the missing field', () => {
      expect(validateLocationForm({ name: 'Home', address: '' })).toEqual({
        address: 'Address is required.',
      })
    })
  })

  describe('firstLocationError', () => {
    it('returns name error first', () => {
      expect(firstLocationError({ name: 'A', address: 'B' })).toBe('A')
    })

    it('returns address error when name is absent', () => {
      expect(firstLocationError({ address: 'B' })).toBe('B')
    })

    it('returns null when no errors', () => {
      expect(firstLocationError({})).toBeNull()
    })
  })
})
