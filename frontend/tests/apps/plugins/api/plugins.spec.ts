/**
 * plugins API client — thin wrapper over the shared api.get helper.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const { getMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: { get: getMock },
}))

import { getPlugins } from '@/apps/plugins/api/plugins'

beforeEach(() => {
  getMock.mockReset()
})

describe('getPlugins', () => {
  it('calls /plugins and unwraps the data.plugins array', async () => {
    const fixture = [
      {
        slug: 'minimax',
        name: 'MiniMax',
        description: '',
        version: 1,
        path: '/plugins/minimax',
        bundledTools: [],
        bundledDrivers: [],
        recipePaths: [],
        migrations: { declared: 1, applied: 1, filesOnDisk: 1, pending: 0, lastAppliedAt: null, status: 'up_to_date' as const },
      },
    ]
    getMock.mockResolvedValueOnce({ plugins: fixture })

    const result = await getPlugins()

    expect(getMock).toHaveBeenCalledWith('/plugins')
    expect(result).toBe(fixture)
  })

  it('returns an empty array when the API returns no plugins', async () => {
    getMock.mockResolvedValueOnce({ plugins: [] })

    const result = await getPlugins()

    expect(result).toEqual([])
  })
})
