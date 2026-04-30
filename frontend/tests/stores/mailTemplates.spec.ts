import { setActivePinia, createPinia } from 'pinia'
import { useMailTemplatesStore } from '@/stores/mailTemplates'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(
      public readonly code: string,
      message: string,
      public readonly status: number,
    ) {
      super(message)
    }
  },
}))

import { api, ApiError } from '@/api/client'

const mockApi = api as ReturnType<typeof vi.fn>

const mockTemplate = {
  id: 1,
  name: 'welcome',
  subject: 'Welcome to Spora',
  body_text: 'Hello {{name}}, welcome aboard!',
  body_html: '<p>Hello {{name}}, welcome aboard!</p>',
}

describe('useMailTemplatesStore', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('fetchAll', () => {
    it('fetches all templates and sets templates state', async () => {
      mockApi.get.mockResolvedValueOnce({ mail_templates: [mockTemplate] })

      const store = useMailTemplatesStore()
      await store.fetchAll()

      expect(store.templates).toEqual([mockTemplate])
      expect(store.loading).toBe(false)
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('UNAUTHORIZED', 'Admin required', 403))

      const store = useMailTemplatesStore()
      await expect(store.fetchAll()).rejects.toThrow(ApiError)
      expect(store.error).toBe('Admin required')
    })
  })

  describe('fetchOne', () => {
    it('fetches single template and sets currentTemplate', async () => {
      mockApi.get.mockResolvedValueOnce({ mail_template: mockTemplate })

      const store = useMailTemplatesStore()
      const result = await store.fetchOne(1)

      expect(store.currentTemplate).toEqual(mockTemplate)
      expect(result).toEqual(mockTemplate)
      expect(store.loading).toBe(false)
    })

    it('sets error on failure', async () => {
      mockApi.get.mockRejectedValueOnce(new ApiError('NOT_FOUND', 'Template not found', 404))

      const store = useMailTemplatesStore()
      await expect(store.fetchOne(99)).rejects.toThrow(ApiError)
      expect(store.error).toBe('Template not found')
    })
  })

  describe('create', () => {
    it('posts to /mail-templates and appends to templates list', async () => {
      const newTemplate = { ...mockTemplate, id: 2, name: 'password_reset' }
      mockApi.post.mockResolvedValueOnce({ mail_template: newTemplate })

      const store = useMailTemplatesStore()
      store.templates = [mockTemplate]

      const result = await store.create({ name: 'password_reset', subject: 'Reset your password', body_text: 'Click {{link}}' })

      expect(mockApi.post).toHaveBeenCalledWith('/mail-templates', {
        name: 'password_reset',
        subject: 'Reset your password',
        body_text: 'Click {{link}}',
      })
      expect(store.templates).toHaveLength(2)
      expect(store.templates[1].name).toBe('password_reset')
      expect(result.name).toBe('password_reset')
      expect(store.saving).toBe(false)
    })

    it('sets error and rethrows on failure', async () => {
      mockApi.post.mockRejectedValueOnce(new ApiError('VALIDATION_ERROR', 'Name is required', 422))

      const store = useMailTemplatesStore()
      await expect(store.create({ name: '', subject: 'No name' })).rejects.toThrow(ApiError)
      expect(store.error).toBe('Name is required')
    })
  })

  describe('update', () => {
    it('patches template and replaces in templates list', async () => {
      const otherTemplate = { ...mockTemplate, id: 2, name: 'welcome' }
      const updated = { ...mockTemplate, subject: 'Updated Subject' }
      mockApi.patch.mockResolvedValueOnce({ mail_template: updated })

      const store = useMailTemplatesStore()
      store.templates = [otherTemplate, mockTemplate]

      const result = await store.update(1, { subject: 'Updated Subject' })

      expect(mockApi.patch).toHaveBeenCalledWith('/mail-templates/1', { subject: 'Updated Subject' })
      const found = store.templates.find(t => t.id === 1)
      expect(found?.subject).toBe('Updated Subject')
      expect(result.subject).toBe('Updated Subject')
      expect(store.saving).toBe(false)
    })

    it('updates currentTemplate when matched', async () => {
      const updated = { ...mockTemplate, subject: 'New Subject' }
      mockApi.patch.mockResolvedValueOnce({ mail_template: updated })

      const store = useMailTemplatesStore()
      store.templates = [mockTemplate]
      store.currentTemplate = mockTemplate

      await store.update(1, { subject: 'New Subject' })

      expect(store.currentTemplate?.subject).toBe('New Subject')
    })
  })

  describe('remove', () => {
    it('deletes template and removes from templates list', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useMailTemplatesStore()
      store.templates = [mockTemplate]

      await store.remove(1)

      expect(mockApi.delete).toHaveBeenCalledWith('/mail-templates/1')
      expect(store.templates).toHaveLength(0)
      expect(store.saving).toBe(false)
    })

    it('clears currentTemplate if deleted template was current', async () => {
      mockApi.delete.mockResolvedValueOnce(undefined)

      const store = useMailTemplatesStore()
      store.templates = [mockTemplate]
      store.currentTemplate = mockTemplate

      await store.remove(1)

      expect(store.currentTemplate).toBe(null)
    })

    it('sets error and rethrows on failure', async () => {
      mockApi.delete.mockRejectedValueOnce(new ApiError('CANNOT_DELETE_SYSTEM_TEMPLATE', 'System templates cannot be deleted', 409))

      const store = useMailTemplatesStore()
      store.templates = [mockTemplate]

      await expect(store.remove(1)).rejects.toThrow(ApiError)
      expect(store.error).toBe('System templates cannot be deleted')
    })
  })

  describe('preview', () => {
    it('builds query string from variables and returns rendered preview', async () => {
      const previewResponse = {
        name: 'welcome',
        subject: 'Welcome Fabian',
        body_text: 'Hello Fabian, welcome aboard!',
        body_html: '<p>Hello Fabian, welcome aboard!</p>',
      }
      mockApi.get.mockResolvedValueOnce(previewResponse)

      const store = useMailTemplatesStore()
      const result = await store.preview('welcome', { name: 'Fabian' })

      expect(mockApi.get).toHaveBeenCalledWith('/mail-templates/welcome/preview?name=Fabian')
      expect(result.subject).toBe('Welcome Fabian')
      expect(result.body_text).toBe('Hello Fabian, welcome aboard!')
    })

    it('handles multiple variables in preview', async () => {
      const previewResponse = { name: 'welcome', subject: 'Hi', body_text: 'Hi User', body_html: null }
      mockApi.get.mockResolvedValueOnce(previewResponse)

      const store = useMailTemplatesStore()
      await store.preview('welcome', { name: 'User', role: 'admin' })

      expect(mockApi.get).toHaveBeenCalledWith('/mail-templates/welcome/preview?name=User&role=admin')
    })
  })
})
