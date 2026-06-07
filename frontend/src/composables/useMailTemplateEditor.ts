/**
 * useMailTemplateEditor — editor state + save/delete/select/create actions
 * for the admin MailTemplatesPage.
 *
 * The page-level MailTemplatesPage.vue becomes a thin shell that mounts
 * 4 sub-views (list, editor, create, preview) and owns the composable
 * instance. The sub-views receive refs + action functions as props.
 */
import { ref, computed } from 'vue'
import { useMailTemplatesStore } from '@/stores/mailTemplates'
import { useToast } from '@/composables/useToast'
import {
  buildUpdatePayload,
  buildCreatePayload,
  validateCreateTemplate,
  emptyCreateDraft,
  insertPlaceholderInto,
  isSystemTemplate as checkIsSystemTemplate,
  MAIL_TEMPLATE_PLACEHOLDERS,
  type MailTemplateCreateDraft,
  type MailTemplateDraft,
} from '@/composables/useMailTemplates'
import type { PreviewPayload } from '@/types/mailTemplate'

export function useMailTemplateEditor() {
  const store = useMailTemplatesStore()
  const toast = useToast()

  const editorForm = ref<MailTemplateDraft & { name: string }>({
    name: '',
    subject: '',
    body_text: '',
    body_html: '',
  })
  const createForm = ref<MailTemplateCreateDraft>(emptyCreateDraft())
  const showCreateModal = ref(false)
  const showPreview = ref(false)
  const previewParams = ref<Record<string, string>>({
    user_name: '',
    email: '',
    site_name: 'Spora',
    verification_link: '',
    reset_link: '',
  })
  const previewLoading = ref(false)
  const previewResult = ref<PreviewPayload | null>(null)

  const placeholders = MAIL_TEMPLATE_PLACEHOLDERS
  const isSystemTemplate = computed(() =>
    store.currentTemplate ? checkIsSystemTemplate(store.currentTemplate.name) : false,
  )

  // Surface the actual exception message when available, otherwise
  // fall back to an action-specific string.
  function reportError(e: unknown, fallback: string): void {
    toast.error(e instanceof Error ? e.message : fallback)
  }

  async function selectTemplate(template: { id: number }): Promise<void> {
    try {
      const loaded = await store.fetchOne(template.id)
      editorForm.value = {
        name: loaded.name,
        subject: loaded.subject,
        body_text: loaded.body_text ?? '',
        body_html: loaded.body_html ?? '',
      }
    } catch (e) {
      reportError(e, 'Failed to load template.')
    }
  }

  function goBack(): void {
    store.currentTemplate = null
  }

  async function saveTemplate(): Promise<void> {
    if (!store.currentTemplate) return
    try {
      const updated = await store.update(
        store.currentTemplate.id,
        buildUpdatePayload(editorForm.value),
      )
      editorForm.value.name = updated.name
      toast.success('Template saved.')
    } catch (e) {
      reportError(e, 'Failed to save template.')
    }
  }

  async function deleteTemplate(): Promise<void> {
    if (!store.currentTemplate || isSystemTemplate.value) return
    try {
      await store.remove(store.currentTemplate.id)
      toast.success('Template deleted.')
      store.currentTemplate = null
    } catch (e) {
      reportError(e, 'Failed to delete template.')
    }
  }

  async function createTemplate(): Promise<void> {
    const err = validateCreateTemplate(createForm.value)
    if (err !== null) {
      toast.error(err)
      return
    }
    try {
      const created = await store.create(buildCreatePayload(createForm.value))
      showCreateModal.value = false
      createForm.value = emptyCreateDraft()
      await selectTemplate(created)
      toast.success('Template created.')
    } catch (e) {
      reportError(e, 'Failed to create template.')
    }
  }

  function openPreview(): void {
    previewResult.value = null
    showPreview.value = true
  }

  async function runPreview(): Promise<void> {
    if (!store.currentTemplate) return
    previewLoading.value = true
    try {
      previewResult.value = await store.preview(
        store.currentTemplate.name,
        previewParams.value,
      )
    } catch (e) {
      reportError(e, 'Failed to generate preview.')
    } finally {
      previewLoading.value = false
    }
  }

  function insertPlaceholder(ph: string): void {
    const inserted = insertPlaceholderInto(editorForm.value.body_text, editorForm.value.body_html, ph)
    editorForm.value.body_text = inserted.body_text
    editorForm.value.body_html = inserted.body_html
  }

  return {
    store,
    editorForm,
    createForm,
    showCreateModal,
    showPreview,
    previewParams,
    previewLoading,
    previewResult,
    placeholders,
    isSystemTemplate,
    selectTemplate,
    goBack,
    saveTemplate,
    deleteTemplate,
    createTemplate,
    openPreview,
    runPreview,
    insertPlaceholder,
  }
}
