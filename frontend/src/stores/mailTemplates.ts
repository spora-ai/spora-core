/**
 * mailTemplates store — email template management.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { MailTemplate, CreateTemplatePayload, UpdateTemplatePayload, PreviewPayload } from '@/types/mailTemplate'

export const useMailTemplatesStore = defineStore('mailTemplates', () => {
  const templates = ref<MailTemplate[]>([])
  const currentTemplate = ref<MailTemplate | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)

  async function fetchAll(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const result = await api.get<{ mail_templates: MailTemplate[] }>('/mail-templates')
      templates.value = result.mail_templates
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load mail templates.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchOne(id: number): Promise<MailTemplate> {
    loading.value = true
    error.value = null
    try {
      const result = await api.get<{ mail_template: MailTemplate }>(`/mail-templates/${id}`)
      currentTemplate.value = result.mail_template
      return result.mail_template
    } catch (e) {
      error.value = e instanceof ApiError ? e.message : 'Failed to load mail template.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function create(payload: CreateTemplatePayload): Promise<MailTemplate> {
    saving.value = true
    error.value = null
    try {
      const result = await api.post<{ mail_template: MailTemplate }>('/mail-templates', payload)
      templates.value.push(result.mail_template)
      return result.mail_template
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to create mail template.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function update(id: number, payload: UpdateTemplatePayload): Promise<MailTemplate> {
    saving.value = true
    error.value = null
    try {
      const result = await api.patch<{ mail_template: MailTemplate }>(`/mail-templates/${id}`, payload)
      const idx = templates.value.findIndex((t) => t.id === id)
      if (idx !== -1) templates.value[idx] = result.mail_template
      if (currentTemplate.value?.id === id) currentTemplate.value = result.mail_template
      return result.mail_template
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to update mail template.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function remove(id: number): Promise<void> {
    saving.value = true
    error.value = null
    try {
      await api.delete(`/mail-templates/${id}`)
      templates.value = templates.value.filter((t) => t.id !== id)
      if (currentTemplate.value?.id === id) currentTemplate.value = null
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Failed to delete mail template.'
      error.value = msg
      throw e
    } finally {
      saving.value = false
    }
  }

  async function preview(name: string, variables: Record<string, string>): Promise<PreviewPayload> {
    const query = new URLSearchParams(variables).toString()
    const result = await api.get<PreviewPayload>(`/mail-templates/${name}/preview?${query}`)
    return result
  }

  return {
    templates,
    currentTemplate,
    loading,
    saving,
    error,
    fetchAll,
    fetchOne,
    create,
    update,
    remove,
    preview,
  }
})
