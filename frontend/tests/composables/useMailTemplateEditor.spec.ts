/**
 * useMailTemplateEditor — editor state + select/save/delete/create/preview
 * actions. Mocks the store + toast; the page-level wrapper is tested in
 * `tests/pages/admin/MailTemplatesPage.spec.ts`.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const currentTemplateRef = ref<{ id: number; name: string; subject: string; body_text: string; body_html: string } | null>(null)
const fetchOneMock = vi.fn()
const updateMock = vi.fn()
const removeMock = vi.fn()
const createMock = vi.fn()
const previewMock = vi.fn()

vi.mock('@/stores/mailTemplates', () => ({
  useMailTemplatesStore: () => ({
    get currentTemplate() { return currentTemplateRef.value },
    set currentTemplate(v) { currentTemplateRef.value = v },
    fetchOne: fetchOneMock,
    update: updateMock,
    remove: removeMock,
    create: createMock,
    preview: previewMock,
  }),
}))

const toastMock = { error: vi.fn(), success: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

import { useMailTemplateEditor } from '@/composables/useMailTemplateEditor'

beforeEach(() => {
  setActivePinia(createPinia())
  currentTemplateRef.value = null
  fetchOneMock.mockReset()
  fetchOneMock.mockResolvedValue({ id: 1, name: 'welcome', subject: 'Hi', body_text: 'x', body_html: '<p>x</p>' })
  updateMock.mockReset()
  updateMock.mockResolvedValue({ name: 'welcome', subject: 'Hi' })
  removeMock.mockReset()
  removeMock.mockResolvedValue(undefined)
  createMock.mockReset()
  createMock.mockResolvedValue({ id: 2 })
  previewMock.mockReset()
  previewMock.mockResolvedValue({ subject: 'Hi', body_text: 'Hello', body_html: '<p>Hello</p>' })
  toastMock.error.mockReset()
  toastMock.success.mockReset()
})

describe('useMailTemplateEditor', () => {
  describe('selectTemplate', () => {
    it('populates editorForm from the store result', async () => {
      const ed = useMailTemplateEditor()
      await ed.selectTemplate({ id: 5 })
      expect(fetchOneMock).toHaveBeenCalledWith(5)
      expect(ed.editorForm.value.name).toBe('welcome')
      expect(ed.editorForm.value.subject).toBe('Hi')
    })

    it('surfaces a toast on failure', async () => {
      fetchOneMock.mockRejectedValueOnce(new Error('boom'))
      const ed = useMailTemplateEditor()
      await ed.selectTemplate({ id: 5 })
      expect(toastMock.error).toHaveBeenCalledWith('Failed to load template.')
    })
  })

  describe('goBack', () => {
    it('clears the store currentTemplate', () => {
      currentTemplateRef.value = { id: 1, name: 'a', subject: 'b', body_text: '', body_html: '' }
      const ed = useMailTemplateEditor()
      ed.goBack()
      expect(currentTemplateRef.value).toBe(null)
    })
  })

  describe('saveTemplate', () => {
    it('is a no-op when there is no current template', async () => {
      const ed = useMailTemplateEditor()
      await ed.saveTemplate()
      expect(updateMock).not.toHaveBeenCalled()
    })

    it('PUTs the payload and toasts on success', async () => {
      currentTemplateRef.value = { id: 7, name: 'a', subject: 'b', body_text: '', body_html: '' }
      const ed = useMailTemplateEditor()
      ed.editorForm.value.subject = 'New subject'
      ed.editorForm.value.body_text = 'hello text'
      ed.editorForm.value.body_html = '<p>hello html</p>'
      await ed.saveTemplate()
      expect(updateMock).toHaveBeenCalledWith(7, {
        subject: 'New subject',
        body_text: 'hello text',
        body_html: '<p>hello html</p>',
      })
      expect(toastMock.success).toHaveBeenCalledWith('Template saved.')
      expect(ed.editorForm.value.name).toBe('welcome')
    })

    it('surfaces an error on failure', async () => {
      currentTemplateRef.value = { id: 7, name: 'a', subject: 'b', body_text: 'x', body_html: 'y' }
      updateMock.mockRejectedValueOnce(new Error('boom'))
      const ed = useMailTemplateEditor()
      await ed.saveTemplate()
      expect(toastMock.error).toHaveBeenCalledWith('boom')
    })
  })

  describe('deleteTemplate', () => {
    it('refuses for system templates', async () => {
      currentTemplateRef.value = { id: 1, name: 'email_verification', subject: 'b', body_text: 'x', body_html: 'y' }
      const ed = useMailTemplateEditor()
      expect(ed.isSystemTemplate.value).toBe(true)
      await ed.deleteTemplate()
      expect(removeMock).not.toHaveBeenCalled()
    })

    it('removes the template and clears the editor on success', async () => {
      currentTemplateRef.value = { id: 1, name: 'custom', subject: 'b', body_text: 'x', body_html: 'y' }
      const ed = useMailTemplateEditor()
      await ed.deleteTemplate()
      expect(removeMock).toHaveBeenCalledWith(1)
      expect(currentTemplateRef.value).toBe(null)
      expect(toastMock.success).toHaveBeenCalledWith('Template deleted.')
    })

    it('surfaces an error on failure', async () => {
      currentTemplateRef.value = { id: 1, name: 'custom', subject: 'b', body_text: 'x', body_html: 'y' }
      removeMock.mockRejectedValueOnce(new Error('nope'))
      const ed = useMailTemplateEditor()
      await ed.deleteTemplate()
      expect(toastMock.error).toHaveBeenCalledWith('nope')
    })
  })

  describe('createTemplate', () => {
    it('rejects empty name with a toast', async () => {
      const ed = useMailTemplateEditor()
      ed.createForm.value.name = ''
      ed.createForm.value.subject = 'Hi'
      await ed.createTemplate()
      expect(toastMock.error).toHaveBeenCalledWith('Name is required.')
      expect(createMock).not.toHaveBeenCalled()
    })

    it('rejects empty subject with a toast', async () => {
      const ed = useMailTemplateEditor()
      ed.createForm.value.name = 'a'
      ed.createForm.value.subject = ''
      await ed.createTemplate()
      expect(toastMock.error).toHaveBeenCalledWith('Subject is required.')
    })

    it('creates the template, closes the modal, and selects the new template on success', async () => {
      const ed = useMailTemplateEditor()
      ed.createForm.value = { name: 'a', subject: 'Hi', body_text: 'x', body_html: 'y' }
      ed.showCreateModal.value = true
      await ed.createTemplate()
      expect(createMock).toHaveBeenCalledWith({ name: 'a', subject: 'Hi', body_text: 'x', body_html: 'y' })
      expect(ed.showCreateModal.value).toBe(false)
      expect(ed.createForm.value.name).toBe('')
      expect(toastMock.success).toHaveBeenCalledWith('Template created.')
    })

    it('surfaces an error on failure', async () => {
      createMock.mockRejectedValueOnce(new Error('boom'))
      const ed = useMailTemplateEditor()
      ed.createForm.value = { name: 'a', subject: 'Hi', body_text: 'x', body_html: 'y' }
      await ed.createTemplate()
      expect(toastMock.error).toHaveBeenCalledWith('boom')
    })
  })

  describe('openPreview + runPreview', () => {
    it('openPreview resets the result and shows the modal', () => {
      const ed = useMailTemplateEditor()
      ed.previewResult.value = { subject: 'old', body_text: 'old', body_html: 'old' }
      ed.openPreview()
      expect(ed.previewResult.value).toBe(null)
      expect(ed.showPreview.value).toBe(true)
    })

    it('runPreview is a no-op when there is no current template', async () => {
      const ed = useMailTemplateEditor()
      await ed.runPreview()
      expect(previewMock).not.toHaveBeenCalled()
    })

    it('runPreview populates previewResult on success', async () => {
      currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'b', body_text: 'x', body_html: 'y' }
      const ed = useMailTemplateEditor()
      ed.previewParams.value.user_name = 'Alice'
      await ed.runPreview()
      expect(previewMock).toHaveBeenCalledWith('welcome', ed.previewParams.value)
      expect(ed.previewResult.value).toEqual({ subject: 'Hi', body_text: 'Hello', body_html: '<p>Hello</p>' })
      expect(ed.previewLoading.value).toBe(false)
    })

    it('runPreview surfaces an error on failure', async () => {
      currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'b', body_text: 'x', body_html: 'y' }
      previewMock.mockRejectedValueOnce(new Error('nope'))
      const ed = useMailTemplateEditor()
      await ed.runPreview()
      expect(toastMock.error).toHaveBeenCalledWith('nope')
    })
  })

  describe('insertPlaceholder', () => {
    it('appends the placeholder to both body fields', () => {
      const ed = useMailTemplateEditor()
      ed.editorForm.value.body_text = 'Hello'
      ed.editorForm.value.body_html = '<p>Hello</p>'
      ed.insertPlaceholder('user_name')
      expect(ed.editorForm.value.body_text).toBe('Hello{{user_name}}')
      expect(ed.editorForm.value.body_html).toBe('<p>Hello</p>{{user_name}}')
    })
  })

  describe('isSystemTemplate', () => {
    it('is true for the system template names', () => {
      currentTemplateRef.value = { id: 1, name: 'password_reset', subject: 'b', body_text: '', body_html: '' }
      const ed = useMailTemplateEditor()
      expect(ed.isSystemTemplate.value).toBe(true)
    })

    it('is false for custom template names', () => {
      currentTemplateRef.value = { id: 1, name: 'order_confirmation', subject: 'b', body_text: '', body_html: '' }
      const ed = useMailTemplateEditor()
      expect(ed.isSystemTemplate.value).toBe(false)
    })

    it('is false when there is no current template', () => {
      const ed = useMailTemplateEditor()
      expect(ed.isSystemTemplate.value).toBe(false)
    })
  })
})
