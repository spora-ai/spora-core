export type TaskStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'PENDING_APPROVAL' | 'CANCELLED'

export type TaskErrorCode = 'RATE_LIMIT' | 'SERVER_OVERLOADED' | 'SERVER_ERROR' | 'GATEWAY_ERROR' | 'AUTH_ERROR' | 'LLM_TIMEOUT' | 'BAD_REQUEST' | 'TOOL_ERROR' | 'UNKNOWN' | 'ORPHANED'

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
