import client from './client'
import axios from 'axios'
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
export interface UserDeleteBlocked {
  message: string
  ticket_count: number
  reassign_to_options: AdminUser[]
}
export interface ResetPasswordPayload {
  /** The acting admin's own password, not the target's. */
  current_password: string
  password: string
}
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
export async function deleteUser(
  id: number,
  reassignTo?: number,
): Promise<void> {
  await client.delete(`/admin/users/${id}`, {
    data: reassignTo === undefined ? undefined : { reassign_to: reassignTo },
  })
}
export async function resetUserPassword(
  id: number,
  payload: ResetPasswordPayload,
): Promise<void> {
  await client.patch(`/admin/users/${id}/password`, payload)
}
/**
 * The 422 that means "choose who inherits the tickets", or null for every
 * other failure — including the last-admin 422, which carries neither key.
 */
export function deleteBlockedBy(error: unknown): UserDeleteBlocked | null {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return null
  const payload = error.response.data as Partial<UserDeleteBlocked> | undefined
  if (
    typeof payload?.ticket_count !== 'number' ||
    !Array.isArray(payload.reassign_to_options)
  )
    return null
  return {
    message: payload.message ?? '',
    ticket_count: payload.ticket_count,
    reassign_to_options: payload.reassign_to_options,
  }
}
