export type TaskStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'PENDING_APPROVAL' | 'CANCELLED'

export type TaskErrorCode = 'RATE_LIMIT' | 'SERVER_OVERLOADED' | 'SERVER_ERROR' | 'GATEWAY_ERROR' | 'AUTH_ERROR' | 'LLM_TIMEOUT' | 'BAD_REQUEST' | 'TOOL_ERROR' | 'UNKNOWN' | 'ORPHANED' | 'NO_LLM_CONFIGURATION'

export type ToolCallStatus = 'PENDING' | 'PENDING_APPROVAL' | 'APPROVED' | 'REJECTED' | 'EXECUTED' | 'FAILED' | 'DISABLED'

export interface Task {
  id: number
  agent_id: number
  status: TaskStatus
  user_prompt: string
  final_response: string | null
  step_count: number
  max_steps: number | null
  parent_task_id?: number
  error_code?: TaskErrorCode | null
  error_message?: string | null
  failure_reason?: string | null
  retry_of_task_id?: number | null
  retry_count?: number
  retry_after?: string | null
  max_retries?: number | null
  retry_after_minutes?: number | null
  created_at: string
  updated_at: string
}

export interface ToolCall {
  id: number
  provider_call_id: string
  tool_name: string
  tool_type: string
  operation: string | null
  operation_description: string | null
  status: ToolCallStatus
  proposed_arguments: Record<string, unknown> | null
  approved_arguments: Record<string, unknown> | null
  human_description: string | null
  result_content: string | null
  executed_at: string | null
  /**
   * Structured data from ToolResult::data, exposed for tools that return
   * actionable links (e.g. HandoverTool's new_task_id). The shape is
   * tool-specific; the chat UI only reads `new_task_id`, `task_id`, and
   * `handover` keys.
   */
  result_data?: Record<string, unknown> | null
  /**
   * JSON Schema for this tool's parameters, derived at serialization time
   * from the live tool instance. Used to render parameters in #[ToolParameter]
   * declaration order in the approval UI, and to drive typed inputs (enum,
   * number, boolean) where the schema provides hints. Optional because
   * historical tool calls whose tool class is no longer registered will be
   * serialized without it.
   */
  parameter_schema?: ParameterSchema
}

/** JSON Schema "parameters" object emitted by ToolParameterSchemaBuilder. */
export interface ParameterSchema {
  type: 'object'
  /**
   * Object map keyed by parameter name. Insertion order is significant — it
   * mirrors #[ToolParameter] declaration order and drives the approval UI's
   * field render order. May be an empty object when the tool takes no params.
   */
  properties: Record<string, ParameterProperty>
  required: string[]
}

export interface ParameterProperty {
  type: 'string' | 'number' | 'integer' | 'boolean' | 'array' | 'object'
  description?: string
  enum?: string[]
  default?: unknown
  minimum?: number
  maximum?: number
  format?: string
  items?: ParameterProperty
}

export interface HistoryEntry {
  sequence: number
  role: 'user' | 'assistant' | 'tool'
  content: string | null
  reasoning: string | null
  tool_call_id: string | null
  tool_name: string | null
}

export interface TaskDetail extends Task {
  tool_calls: ToolCall[]
  history: HistoryEntry[]
}
