import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { PromptTemplateResource } from '@/types/promptTemplate'

export const usePromptTemplatesStore = defineStore('promptTemplates', () => {
  const templates = ref<PromptTemplateResource[]>([])

  async function fetchTemplates(agentId: number): Promise<PromptTemplateResource[]> {
    const result = await api.get<{ data: { templates: PromptTemplateResource[] } }>(
      `/agents/${agentId}/templates`,
    )
    templates.value = result.data.templates
    return result.data.templates
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
    const result = await api.post<{ data: { template: PromptTemplateResource } }>(
      `/agents/${agentId}/templates`,
      payload,
    )
    templates.value.unshift(result.data.template)
    return result.data.template
  }

  return { templates, fetchTemplates, createTemplate }
})
