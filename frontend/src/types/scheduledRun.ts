export interface ScheduledRunResource {
  id: number
  agent_id: number
  template_id: number | null
  template_name?: string | null
  raw_prompt: string | null
  cron_expression: string | null
  run_at: string | null
  timezone: string
  max_steps_override: number | null
  is_active: boolean
  last_run_at: string | null
  next_run_at: string | null
  created_at: string
  updated_at: string
}

export interface ScheduledRunResourceSummary {
  id: number
  agent_id: number
  name: string
  schedule_description: string
  is_active: boolean
  last_run_at: string | null
  next_run_at: string | null
}
