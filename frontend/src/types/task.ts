export type TaskStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'PENDING_APPROVAL'

export type ToolCallStatus = 'PENDING' | 'APPROVED' | 'REJECTED' | 'EXECUTED' | 'FAILED'

export interface Task {
  id: number
  agent_id: number
  status: TaskStatus
  user_prompt: string
  final_response: string | null
  step_count: number
  max_steps: number | null
  created_at: string
  updated_at: string
}

export interface ToolCall {
  id: number
  tool_name: string
  tool_type: string
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
