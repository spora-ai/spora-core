/**
 * Types for LLM Driver Configurations.
 */

export interface LLMSettingsSchema {
  key: string
  label: string
  type: 'text' | 'password' | 'select' | 'textarea' | 'toggle'
  description: string
  default: unknown
  required: boolean
  scope: 'global' | 'agent'
  options: Record<string, string> | string[] | null
  expose_to_llm: boolean
}

export interface LLMDriverInfo {
  name: string
  display_name: string
  driver_class: string
  settings_schema: LLMSettingsSchema[]
}

export interface LLMConfigResource {
  id: number
  name: string
  driver_class: string
  driver_name: string
  driver_display_name: string
  settings: Record<string, string>
  context_window: number | null
  max_tokens_output: number | null
  is_default: boolean
  is_global: boolean
  user_id: number | null
  created_at: string
  updated_at: string
}
