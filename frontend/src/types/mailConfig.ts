export interface MailConfig {
  driver: string
  host: string | null
  port: number
  username: string | null
  password: string | null
  from_address: string | null
  from_name: string
  encryption: string
}

export interface MailConfigPayload {
  driver?: string
  host?: string | null
  port?: number
  username?: string | null
  password?: string | null
  from_address?: string | null
  from_name?: string
  encryption?: string
}
