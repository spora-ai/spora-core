import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { PromptTemplateResource } from '@/types/promptTemplate'

/**
 * Manages prompt templates for agents: fetch, create, update, and delete.
 */
export const usePromptTemplatesStore = defineStore('promptTemplates', () => {
  const templates = ref<PromptTemplateResource[]>([])

  async function fetchTemplates(agentId: number): Promise<PromptTemplateResource[]> {
    const result = await api.get<{ templates: PromptTemplateResource[] }>(
      `/agents/${agentId}/templates`,
    )
    templates.value = result.templates
    return result.templates
  }

  async function createTemplate(
    agentId: number,
    payload: {
      name: string
      description?: string
      prompt_template: string
      variables?: Array<{ key: string; label?: string; default_value?: string }>
      max_steps?: number | null
      is_active?: boolean
    },
  ): Promise<PromptTemplateResource> {
    const result = await api.post<{ template: PromptTemplateResource }>(
      `/agents/${agentId}/templates`,
      payload,
    )
    templates.value.unshift(result.template)
    return result.template
  }

  async function updateTemplate(
    agentId: number,
    templateId: number,
    payload: {
      name?: string
      description?: string
      prompt_template?: string
      variables?: Array<{ key: string; label?: string; default_value?: string }>
      max_steps?: number | null
      is_active?: boolean
    },
  ): Promise<PromptTemplateResource> {
    const result = await api.put<{ template: PromptTemplateResource }>(
      `/agents/${agentId}/templates/${templateId}`,
      payload,
    )
    const idx = templates.value.findIndex((t) => t.id === templateId)
    if (idx !== -1) {
      templates.value[idx] = result.template
    }
    return result.template
  }

  async function deleteTemplate(agentId: number, templateId: number): Promise<void> {
    await api.delete(`/agents/${agentId}/templates/${templateId}`)
    templates.value = templates.value.filter((t) => t.id !== templateId)
  }

  return { templates, fetchTemplates, createTemplate, updateTemplate, deleteTemplate }
})
