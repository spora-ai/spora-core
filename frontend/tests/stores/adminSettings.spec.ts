import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach } from 'vitest'
import { useAdminSettingsStore } from '@/stores/adminSettings'

describe('useAdminSettingsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('defaults activeSection to null', () => {
    const store = useAdminSettingsStore()
    expect(store.activeSection).toBeNull()
  })

  it('setActiveSection updates activeSection', () => {
    const store = useAdminSettingsStore()
    store.setActiveSection('users')
    expect(store.activeSection).toBe('users')
  })

  it('setActiveSection can clear back to null', () => {
    const store = useAdminSettingsStore()
    store.setActiveSection('drivers')
    store.setActiveSection(null)
    expect(store.activeSection).toBeNull()
  })
})
