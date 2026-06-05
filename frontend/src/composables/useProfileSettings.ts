/**
 * useProfileSettings — pure helpers for ProfileSettingsPage.
 *
 * Owns profile/location form shape, validation, and the
 * "extract typed error message from unknown" helper. The SFC keeps the
 * template, modal visibility, store calls, and timeout-based "Saved!" flash.
 */

export interface UserProfile {
  name: string | null
  date_of_birth: string | null
  about_me: string | null
  height_cm: number | null
  weight_kg: number | null
}

export interface UserLocation {
  id: number
  name: string
  address: string
  is_default: boolean
}

/** Empty profile form for the initial render. */
export function emptyProfile(): UserProfile {
  return {
    name: null,
    date_of_birth: null,
    about_me: null,
    height_cm: null,
    weight_kg: null,
  }
}

/** Empty location form for the "add location" modal. */
export function emptyLocationForm(): { name: string; address: string; is_default: boolean } {
  return { name: '', address: '', is_default: false }
}

export interface LocationFormErrors {
  name?: string
  address?: string
}

/** Validate the location form, returning a per-field error map. */
export function validateLocationForm(form: {
  name: string
  address: string
}): LocationFormErrors {
  const errors: LocationFormErrors = {}
  if (!form.name.trim()) errors.name = 'Name is required.'
  if (!form.address.trim()) errors.address = 'Address is required.'
  return errors
}

/** First error message in a per-field map, or null. */
export function firstLocationError(errors: LocationFormErrors): string | null {
  return errors.name ?? errors.address ?? null
}
