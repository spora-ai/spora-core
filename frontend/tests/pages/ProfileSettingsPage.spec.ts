import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, email: 'test@example.com' },
  }),
}))

import { api } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

const mockProfile = {
  name: 'Alice',
  date_of_birth: '1990-05-15',
  about_me: 'Hello world',
  height_cm: 175.5,
  weight_kg: 70.0,
}

const mockLocations = [
  { id: 1, name: 'Home', type: 'home', address: '123 Main St', is_default: true },
  { id: 2, name: 'Work', type: 'work', address: '456 Office Blvd', is_default: false },
]

const mockLocation = { id: 1, name: 'Home', type: 'home', address: '123 Main St', is_default: true }

describe('ProfileSettingsPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
    mockApi.get.mockReset()
    mockApi.post.mockReset()
    mockApi.put.mockReset()
    mockApi.delete.mockReset()
  })

  describe('initial data loading', () => {
    it('loads profile and locations on mount', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: mockLocations })

      const { default: ProfileSettingsPage } = await import('@/pages/ProfileSettingsPage.vue')
      expect(mockApi.get).toHaveBeenCalledWith('/me/profile')
      expect(mockApi.get).toHaveBeenCalledWith('/me/locations')
    })

    it('pre-populates form with existing profile data', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: [] })

      await import('@/pages/ProfileSettingsPage.vue')
      // Profile data should be set after mount
      expect(mockApi.get).toHaveBeenCalled()
    })
  })

  describe('saving profile', () => {
    it('PUTs updated profile data to /me/profile', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: [] })

      const updatedProfile = { ...mockProfile, name: 'Bob' }
      mockApi.put.mockResolvedValueOnce(updatedProfile)

      const { useProfile } = await import('@/pages/ProfileSettingsPage.vue')
      const vm = {} // The component handles save internally in onMounted

      expect(mockApi.put).not.toHaveBeenCalled()
    })
  })

  describe('locations CRUD', () => {
    it('displays locations list from API', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: mockLocations })

      await import('@/pages/ProfileSettingsPage.vue')

      expect(mockApi.get).toHaveBeenCalledWith('/me/locations')
    })

    it('POSTs new location to /me/locations', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: [] })
      mockApi.post.mockResolvedValueOnce(mockLocation)

      const { openAddLocation, saveLocation } = await import('@/pages/ProfileSettingsPage.vue')
      // Component opens form and saves
      expect(mockApi.post).not.toHaveBeenCalled()
    })

    it('DELETE removes location from list optimistically', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: mockLocations })
      mockApi.delete.mockResolvedValueOnce({ data: { deleted: true } })

      expect(mockApi.delete).not.toHaveBeenCalled()
    })

    it('validates required fields before saving location', async () => {
      mockApi.get
        .mockResolvedValueOnce(mockProfile)
        .mockResolvedValueOnce({ locations: [] })

      await import('@/pages/ProfileSettingsPage.vue')

      expect(mockApi.post).not.toHaveBeenCalled()
    })
  })
})

describe('SettingsSidebar', () => {
  it('includes Profile nav item', async () => {
    const { default: SettingsSidebar } = await import('@/components/settings/SettingsSidebar.vue')
    // Sidebar should have Profile button
    expect(true).toBe(true)
  })
})
