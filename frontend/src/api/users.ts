import client from './client'
import type { UserRole } from './auth'
import type { Paginated } from './pagination'
export interface AdminUser {
  id: number
  name: string
  email: string
  role: UserRole
  is_active: boolean
  created_at: string
  tickets_count?: number
}
export interface UserListQuery {
  search?: string
  status?: 'active' | 'inactive'
  role?: UserRole
  page?: number
  per_page?: number
}
export interface CreateUserPayload {
  name: string
  email: string
  password: string
  role: UserRole
  is_active?: boolean
}
export type UpdateUserPayload = Partial<
  Pick<AdminUser, 'name' | 'email' | 'role' | 'is_active'>
>
export async function listUsers(
  query: UserListQuery = {},
): Promise<Paginated<AdminUser>> {
  const { data } = await client.get<Paginated<AdminUser>>('/admin/users', {
    params: query,
  })
  return data
}
export async function createUser(
  payload: CreateUserPayload,
): Promise<AdminUser> {
  const { data } = await client.post<{ data: AdminUser }>(
    '/admin/users',
    payload,
  )
  return data.data
}
export async function updateUser(
  id: number,
  payload: UpdateUserPayload,
): Promise<AdminUser> {
  const { data } = await client.patch<{ data: AdminUser }>(
    `/admin/users/${id}`,
    payload,
  )
  return data.data
}
