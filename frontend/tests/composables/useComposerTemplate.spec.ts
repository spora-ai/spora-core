/**
 * useComposerTemplate — template selection + save-as-template flow.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirmDialog', () => ({
  useConfirmDialog: () => ({ confirm: confirmMock }),
}))

const templatesRef = ref<Array<{ id: number; name: string; prompt_template: string; variables: string[] }>>([])
const deleteTemplateMock = vi.fn()

vi.mock('@/stores/promptTemplates', () => ({
  usePromptTemplatesStore: () => ({
    get templates() { return templatesRef.value },
    deleteTemplate: deleteTemplateMock,
  }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class FakeApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import { useComposerTemplate } from '@/composables/useComposerTemplate'

beforeEach(() => {
  setActivePinia(createPinia())
  confirmMock.mockReset()
  confirmMock.mockResolvedValue(true)
  deleteTemplateMock.mockReset()
  deleteTemplateMock.mockResolvedValue(undefined)
  templatesRef.value = []
})

describe('useComposerTemplate', () => {
  it('clears the prompt when templateId is null', () => {
    const setPrompt = vi.fn()
    const t = useComposerTemplate(1, setPrompt)
    t.selectedTemplateId.value = 5
    t.onTemplateChange(null)
    expect(t.selectedTemplateId.value).toBe(null)
    expect(setPrompt).toHaveBeenCalledWith('')
  })

  it('builds the prompt from the template when templateId is a known id', () => {
    templatesRef.value = [{
      id: 7,
      name: 'Greet',
      prompt_template: 'Hello {{name}}',
      variables: ['name'],
    }]
    const setPrompt = vi.fn()
    const t = useComposerTemplate(1, setPrompt)
    t.onTemplateChange(7)
    expect(t.selectedTemplateId.value).toBe(7)
    expect(setPrompt).toHaveBeenCalled()
    // The "Hello {{name}}" stays raw until renderMarkdown later — the composable
    // just sets the raw template text via buildPromptFromTemplate.
    expect(setPrompt.mock.calls[0]?.[0]).toContain('Hello')
  })

  it('no-ops when templateId points to an unknown template', () => {
    templatesRef.value = [{ id: 1, name: 'A', prompt_template: 'a', variables: [] }]
    const setPrompt = vi.fn()
    const t = useComposerTemplate(1, setPrompt)
    t.onTemplateChange(999)
    expect(t.selectedTemplateId.value).toBe(999)
    expect(setPrompt).not.toHaveBeenCalled()
  })

  it('deleteSelectedTemplate is a no-op when no template is selected', async () => {
    const t = useComposerTemplate(1, vi.fn())
    await t.deleteSelectedTemplate()
    expect(confirmMock).not.toHaveBeenCalled()
    expect(deleteTemplateMock).not.toHaveBeenCalled()
  })

  it('deleteSelectedTemplate asks for confirmation and proceeds on yes', async () => {
    templatesRef.value = [{ id: 3, name: 'A', prompt_template: 'a', variables: [] }]
    const setPrompt = vi.fn()
    const t = useComposerTemplate(1, setPrompt)
    t.selectedTemplateId.value = 3
    await t.deleteSelectedTemplate()
    expect(confirmMock).toHaveBeenCalled()
    expect(deleteTemplateMock).toHaveBeenCalledWith(1, 3)
    expect(t.selectedTemplateId.value).toBe(null)
    expect(setPrompt).toHaveBeenCalledWith('')
  })

  it('deleteSelectedTemplate does not delete when user cancels', async () => {
    confirmMock.mockResolvedValueOnce(false)
    const t = useComposerTemplate(1, vi.fn())
    t.selectedTemplateId.value = 3
    await t.deleteSelectedTemplate()
    expect(deleteTemplateMock).not.toHaveBeenCalled()
    expect(t.selectedTemplateId.value).toBe(3)
  })

  it('deleteSelectedTemplate surfaces an ApiError on failure', async () => {
    const { ApiError } = await import('@/api/client')
    deleteTemplateMock.mockRejectedValueOnce(new ApiError('not allowed'))
    const t = useComposerTemplate(1, vi.fn())
    t.selectedTemplateId.value = 3
    await t.deleteSelectedTemplate()
    expect(t.error.value).toBe('not allowed')
  })

  it('openSaveDialog is a no-op for a non-finite agentId', () => {
    const t = useComposerTemplate(NaN, vi.fn())
    t.openSaveDialog()
    expect(t.showSaveDialog.value).toBe(false)
  })

  it('openSaveDialog opens the dialog for a finite agentId', () => {
    const t = useComposerTemplate(7, vi.fn())
    t.openSaveDialog()
    expect(t.showSaveDialog.value).toBe(true)
  })

  it('onTemplateSaved updates the selected id', () => {
    const t = useComposerTemplate(1, vi.fn())
    t.onTemplateSaved({ id: 11 })
    expect(t.selectedTemplateId.value).toBe(11)
  })
})
