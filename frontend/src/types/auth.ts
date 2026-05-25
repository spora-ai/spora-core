export interface LoginCredentials {
  email: string
  password: string
  remember_me?: boolean
}

export interface RegisterPayload {
  email: string
  password: string
}

export interface PasswordChangePayload {
  current_password: string
  new_password: string
}

export interface EmailChangePayload {
  email: string
}

export interface ForgotPasswordPayload {
  email: string
}

export interface ResendVerificationPayload {
  email: string
}

export interface ApiConfig {
  allow_registration: boolean
}
