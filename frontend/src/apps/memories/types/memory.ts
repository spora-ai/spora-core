export interface MemoryResource {
  id: number
  user_id: number | null
  agent_id: number | null
  name: string
  summary: string | null
  content: string | null
  order: number
  created_at: string
  updated_at: string
}

export interface CreateMemoryDto {
  name: string
  summary?: string
  content?: string
  order?: number
}

export interface UpdateMemoryDto {
  name?: string
  summary?: string | null
  content?: string | null
  order?: number
}
