/**
 * plugins store — covers load() including error path.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const { getPluginsMock } = vi.hoisted(() => ({
  getPluginsMock: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

vi.mock('@/apps/plugins/api/plugins', () => ({
  getPlugins: getPluginsMock,
}))

import { usePluginsStore } from '@/apps/plugins/stores/plugins'
import { ApiError } from '@/api/client'

beforeEach(() => {
  setActivePinia(createPinia())
  getPluginsMock.mockReset()
})

describe('plugins store', () => {
  it('load() populates plugins on success', async () => {
    const fixture = [
      {
        slug: 'minimax',
        name: 'MiniMax',
        description: 'Image generation',
        icon: 'puzzle',
        version: 1,
        path: '/plugins/minimax',
        bundledTools: [],
        bundledDrivers: [],
        recipePaths: [],
        migrations: { declared: 1, applied: 1, filesOnDisk: 1, pending: 0, lastAppliedAt: null, status: 'up_to_date' as const },
      },
    ]
    getPluginsMock.mockResolvedValueOnce(fixture)

    const store = usePluginsStore()
    await store.load()

    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.plugins).toEqual(fixture)
  })

  it('load() sets error message on ApiError', async () => {
    getPluginsMock.mockRejectedValueOnce(new ApiError('Boom', 'BOOM_CODE', 500))

    const store = usePluginsStore()
    await store.load()

    expect(store.loading).toBe(false)
    expect(store.error).toBe('Boom')
    expect(store.plugins).toEqual([])
  })

  it('load() sets a generic error for non-ApiError rejections', async () => {
    getPluginsMock.mockRejectedValueOnce(new Error('Network'))

    const store = usePluginsStore()
    await store.load()

    expect(store.error).toBe('Failed to load plugins.')
  })

  it('load() clears the previous error on a fresh call', async () => {
    getPluginsMock.mockRejectedValueOnce(new Error('First failure'))
    const store = usePluginsStore()
    await store.load()
    expect(store.error).toBe('Failed to load plugins.')

    getPluginsMock.mockResolvedValueOnce([])
    await store.load()
    expect(store.error).toBeNull()
    expect(store.plugins).toEqual([])
  })
})
