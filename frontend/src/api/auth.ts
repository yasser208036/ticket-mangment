import client from './client'

export type UserRole = 'admin' | 'agent' | 'user'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: UserRole
  is_active: boolean
  created_at: string
}

export interface LoginResponse {
  token: string
  token_type: string
  user: AuthUser
}

export async function login(
  email: string,
  password: string,
): Promise<LoginResponse> {
  const { data } = await client.post<LoginResponse>('/auth/login', {
    email,
    password,
  })
  return data
}

export async function me(): Promise<AuthUser> {
  const { data } = await client.get<{ user: AuthUser }>('/auth/me')
  return data.user
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout')
}

export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export async function changePassword(
  payload: ChangePasswordPayload,
): Promise<void> {
  await client.patch('/auth/password', payload)
}
