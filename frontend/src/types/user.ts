export interface User {
  id: number
  email: string
  name: string | null
  is_admin: boolean
  roles: string[]
  created_at?: string
  registered?: string
  suspended?: boolean
}

export interface PaginatedUsers {
  users: User[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface CreateUserPayload {
  email: string
  password: string
}

export interface UpdateUserPayload {
  name?: string
  is_admin?: boolean
  suspended?: boolean
}
