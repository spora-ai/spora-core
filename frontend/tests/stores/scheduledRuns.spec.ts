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

import { api } from '@/api/client'
import { useScheduledRunsStore } from '@/stores/scheduledRuns'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  put: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

const sampleRun = {
  id: 1,
  agent_id: 10,
  name: 'Daily summary',
  cron_expression: '0 9 * * *',
  prompt: 'Summarize',
  enabled: true,
  next_run_at: '2026-01-02T09:00:00Z',
  last_run_at: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

describe('useScheduledRunsStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchRuns', () => {
    it('fetches scheduled runs and stores them', async () => {
      mockApi.get.mockResolvedValueOnce({ scheduled_runs: [sampleRun] })

      const store = useScheduledRunsStore()
      const result = await store.fetchRuns(10)

      expect(mockApi.get).toHaveBeenCalledWith('/agents/10/scheduled-runs')
      expect(store.runs).toEqual([sampleRun])
      expect(result).toEqual([sampleRun])
    })
  })

  describe('createRun', () => {
    it('posts new run and prepends to list', async () => {
      mockApi.post.mockResolvedValueOnce({ scheduled_run: sampleRun })

      const store = useScheduledRunsStore()
      store.runs = []
      const result = await store.createRun(10, { name: 'Daily summary' })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/agents/10/scheduled-runs',
        { name: 'Daily summary' },
      )
      expect(store.runs[0]).toEqual(sampleRun)
      expect(result).toEqual(sampleRun)
    })
  })

  describe('updateRun', () => {
    it('puts run update and replaces in list', async () => {
      const updated = { ...sampleRun, name: 'Updated' }
      mockApi.put.mockResolvedValueOnce({ scheduled_run: updated })

      const store = useScheduledRunsStore()
      store.runs = [sampleRun]

      const result = await store.updateRun(10, 1, { name: 'Updated' })

      expect(mockApi.put).toHaveBeenCalledWith(
        '/agents/10/scheduled-runs/1',
        { name: 'Updated' },
      )
      expect(store.runs[0].name).toBe('Updated')
      expect(result.name).toBe('Updated')
    })

    it('still returns the updated run when not present in list', async () => {
      const updated = { ...sampleRun, name: 'Updated' }
      mockApi.put.mockResolvedValueOnce({ scheduled_run: updated })

      const store = useScheduledRunsStore()
      store.runs = []

      const result = await store.updateRun(10, 1, { name: 'Updated' })

      expect(result).toEqual(updated)
      expect(store.runs).toEqual([])
    })
  })

  describe('deleteRun', () => {
    it('deletes the run and removes it from the list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useScheduledRunsStore()
      store.runs = [sampleRun, { ...sampleRun, id: 2 }]

      await store.deleteRun(10, 1)

      expect(mockApi.delete).toHaveBeenCalledWith('/agents/10/scheduled-runs/1')
      expect(store.runs.map(r => r.id)).toEqual([2])
    })
  })

  describe('triggerRun', () => {
    it('posts the trigger endpoint', async () => {
      mockApi.post.mockResolvedValueOnce({ scheduled_run: sampleRun })

      const store = useScheduledRunsStore()
      await store.triggerRun(10, 1)

      expect(mockApi.post).toHaveBeenCalledWith('/agents/10/scheduled-runs/1/trigger')
    })
  })
})
