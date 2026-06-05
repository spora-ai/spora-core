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
import { usePromptTemplatesStore } from '@/stores/promptTemplates'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  put: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

const sampleTemplate = {
  id: 1,
  agent_id: 5,
  name: 'Welcome',
  description: 'Welcome email',
  prompt_template: 'Hello {{name}}',
  variables: [{ key: 'name', label: 'Name', default_value: '' }],
  max_steps: 5,
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

describe('usePromptTemplatesStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchTemplates', () => {
    it('fetches templates and stores them', async () => {
      mockApi.get.mockResolvedValueOnce({ templates: [sampleTemplate] })

      const store = usePromptTemplatesStore()
      const result = await store.fetchTemplates(5)

      expect(mockApi.get).toHaveBeenCalledWith('/agents/5/templates')
      expect(store.templates).toEqual([sampleTemplate])
      expect(result).toEqual([sampleTemplate])
    })
  })

  describe('createTemplate', () => {
    it('posts new template and prepends', async () => {
      mockApi.post.mockResolvedValueOnce({ template: sampleTemplate })

      const store = usePromptTemplatesStore()
      store.templates = []

      const result = await store.createTemplate(5, {
        name: 'Welcome',
        prompt_template: 'Hello {{name}}',
      })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/agents/5/templates',
        { name: 'Welcome', prompt_template: 'Hello {{name}}' },
      )
      expect(store.templates[0]).toEqual(sampleTemplate)
      expect(result).toEqual(sampleTemplate)
    })
  })

  describe('updateTemplate', () => {
    it('puts template update and replaces in list', async () => {
      const updated = { ...sampleTemplate, name: 'Updated' }
      mockApi.put.mockResolvedValueOnce({ template: updated })

      const store = usePromptTemplatesStore()
      store.templates = [sampleTemplate]

      const result = await store.updateTemplate(5, 1, { name: 'Updated' })

      expect(mockApi.put).toHaveBeenCalledWith(
        '/agents/5/templates/1',
        { name: 'Updated' },
      )
      expect(store.templates[0].name).toBe('Updated')
      expect(result.name).toBe('Updated')
    })

    it('does not replace anything when template id missing from list', async () => {
      const updated = { ...sampleTemplate, name: 'Updated' }
      mockApi.put.mockResolvedValueOnce({ template: updated })

      const store = usePromptTemplatesStore()
      store.templates = []

      const result = await store.updateTemplate(5, 1, { name: 'Updated' })

      expect(result).toEqual(updated)
      expect(store.templates).toEqual([])
    })
  })

  describe('deleteTemplate', () => {
    it('deletes the template and removes it from the list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = usePromptTemplatesStore()
      store.templates = [sampleTemplate, { ...sampleTemplate, id: 2 }]

      await store.deleteTemplate(5, 1)

      expect(mockApi.delete).toHaveBeenCalledWith('/agents/5/templates/1')
      expect(store.templates.map(t => t.id)).toEqual([2])
    })
  })
})
