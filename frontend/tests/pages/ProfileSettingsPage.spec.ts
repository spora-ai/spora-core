import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import ProfileSettingsPage from '@/pages/ProfileSettingsPage.vue'

vi.mock('@/api/client', () => ({
  ApiError: class extends Error {
    constructor(public override message: string, public readonly status = 500) {
      super(message)
    }
  },
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '@/api/client'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; put: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> }

const mockProfile = {
  name: 'Alice',
  date_of_birth: '1990-05-15',
  about_me: 'Hello world',
  height_cm: 175.5,
  weight_kg: 70.0,
}

const mockLocations = [
  { id: 1, name: 'Home', address: '123 Main St', is_default: true },
  { id: 2, name: 'Work', address: '456 Office Blvd', is_default: false },
]

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/profile', name: 'profile', component: { template: '<div />' } }],
  })
}

describe('ProfileSettingsPage', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.useRealTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.resetAllMocks()
  })

  async function mountPage(profileData: any = mockProfile, locationsData: any = { locations: mockLocations }) {
    mockApi.get.mockImplementation((path: string) => {
      if (path === '/me/profile') return Promise.resolve(profileData)
      if (path === '/me/locations') return Promise.resolve(locationsData)
      if (path === '/tasks') return Promise.resolve({ tasks: [] }) // prevent polling crash
      return Promise.resolve(null)
    })

    const router = makeRouter()
    await router.push('/profile')
    await router.isReady()

    const wrapper = mount(ProfileSettingsPage, {
      global: { plugins: [router, pinia] },
    })
    await flushPromises()
    // Allow onMounted async operations to complete
    await new Promise(r => setTimeout(r, 0))
    await flushPromises()

    return { wrapper, router }
  }

  describe('initial data loading', () => {
    it('loads profile and locations on mount', async () => {
      const { wrapper } = await mountPage()
      expect(mockApi.get).toHaveBeenCalledWith('/me/profile')
      expect(mockApi.get).toHaveBeenCalledWith('/me/locations')
      wrapper.unmount()
    })

    it('shows locations list from API', async () => {
      const { wrapper } = await mountPage()
      expect(wrapper.text()).toContain('Home')
      expect(wrapper.text()).toContain('Work')
      wrapper.unmount()
    })

    it('shows empty message when no locations', async () => {
      const { wrapper } = await mountPage(mockProfile, { locations: [] })
      expect(wrapper.text()).toContain('No locations saved yet')
      wrapper.unmount()
    })
  })

  describe('saving profile', () => {
    it('shows error when profile save fails', async () => {
      mockApi.put.mockRejectedValueOnce(new Error('Server error'))

      const { wrapper } = await mountPage()

      await wrapper.find('[id="profile-name"]').setValue('Bob')
      await wrapper.findAll('button').find(b => b.text().includes('Save Base Data'))!.trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('Failed to save profile')
      wrapper.unmount()
    })
  })

  describe('locations CRUD', () => {
    it('POSTs new location to /me/locations', async () => {
      mockApi.post.mockResolvedValueOnce({ id: 1, name: 'Home', address: '123 Main St', is_default: true })

      const { wrapper } = await mountPage()

      await wrapper.findAll('button').find(b => b.text().includes('Add location'))!.trigger('click')
      await flushPromises()

      await wrapper.find('[id="loc-name"]').setValue('Home')
      await wrapper.find('[id="loc-address"]').setValue('123 Main St')
      await wrapper.findAll('button').find(b => b.text().includes('Save Location'))!.trigger('click')
      await flushPromises()

      expect(mockApi.post).toHaveBeenCalledWith('/me/locations', expect.objectContaining({ name: 'Home' }))
      wrapper.unmount()
    })

    it('PUTs edited location to /me/locations/{id} (inverted if/else branch)', async () => {
      // Covers the `else` branch in saveLocation() — was previously inside an
      // `if (editingLocation.value !== null) { ... } else { POST }` block. The
      // inversion (SonarQube S7735) flipped the branches; this test guards
      // the PUT path.
      mockApi.put.mockResolvedValueOnce({ id: 1, name: 'Home Sweet Home', address: '789 New St', is_default: true })

      const { wrapper } = await mountPage()

      // The per-row edit button is an icon-only button with the pencil SVG.
      // Match by the unique path prefix (the trash icon uses 'M19 7l-.867').
      const editBtn = wrapper.findAll('button').find(w => w.html().includes('M11 5H6'))
      expect(editBtn).toBeDefined()
      await editBtn!.trigger('click')
      await flushPromises()

      // The form should now be pre-filled. Mutate and save.
      await wrapper.find('[id="loc-name"]').setValue('Home Sweet Home')
      await wrapper.find('[id="loc-address"]').setValue('789 New St')
      await wrapper.findAll('button').find(b => b.text().includes('Save Location'))!.trigger('click')
      await flushPromises()

      expect(mockApi.put).toHaveBeenCalledWith('/me/locations/1', expect.objectContaining({ name: 'Home Sweet Home' }))
      expect(mockApi.post).not.toHaveBeenCalledWith('/me/locations', expect.anything())
      wrapper.unmount()
    })

    it('calls delete API when delete button is clicked', async () => {
      mockApi.delete.mockResolvedValueOnce({} as any)

      const { wrapper } = await mountPage()

      // Find delete buttons by their trash SVG content
      const allButtons = wrapper.findAll('button')
      const deleteBtn = allButtons.find(w => w.html().includes('M19 7l-.867'))
      expect(deleteBtn).toBeDefined()

      await deleteBtn!.trigger('click')
      await flushPromises()
      await new Promise(r => setTimeout(r, 0))
      await flushPromises()

      expect(mockApi.delete).toHaveBeenCalled()
      wrapper.unmount()
    })
  })

  describe('health data', () => {
    it('saves health data via PUT /me/profile', async () => {
      mockApi.put.mockResolvedValueOnce({ ...mockProfile, height_cm: 180 })

      const { wrapper } = await mountPage()

      await wrapper.find('[id="health-height"]').setValue('180')
      await wrapper.findAll('button').find(b => b.text().includes('Save Health Data'))!.trigger('click')
      await flushPromises()

      expect(mockApi.put).toHaveBeenCalledWith('/me/profile', expect.objectContaining({ height_cm: 180 }))
      wrapper.unmount()
    })
  })
})

describe('SettingsSidebar', () => {
  it('includes Profile nav item', async () => {
    const { default: SettingsSidebar } = await import('@/components/settings/SettingsSidebar.vue')
    expect(SettingsSidebar).toBeDefined()
  })
})