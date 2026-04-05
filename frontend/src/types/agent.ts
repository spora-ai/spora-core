export interface AgentTool {
  tool_class: string
  tool_name: string
  auto_approve: boolean | null
}

export interface Agent {
  id: number
  name: string
  description: string | null
  recipe_id: string | null
  system_prompt: string | null
  llm_driver_config_id: number | null
  max_steps: number
  is_active: boolean
  tools: AgentTool[]
}

export interface LLMConfigSettings {
  'core.openai.api_key'?: string
  'core.anthropic.api_key'?: string
  'core.openai.base_url'?: string
  'core.anthropic.base_url'?: string
}
