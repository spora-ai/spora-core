export interface User {
  id: number
  email: string
  username: string | null
  name: string | null
  is_admin: boolean
  roles: string[]
  verified: boolean
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
  username?: string
  name?: string
  is_admin?: boolean
  suspended?: boolean
  verified?: boolean
}
