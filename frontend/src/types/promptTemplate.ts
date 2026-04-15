export interface PromptTemplateVariable {
  key: string
  label?: string
  default_value?: string
}

export interface PromptTemplateResource {
  id: number
  agent_id: number
  name: string
  description: string | null
  prompt_template: string
  variables: PromptTemplateVariable[]
  max_steps: number | null
  is_active: boolean
  created_at: string
  updated_at: string
}
