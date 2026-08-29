import client from './client'
import axios from 'axios'
export interface CategoryDeleteBlocked {
  message: string
  ticket_count: number
  reassign_to_options: Category[]
}
export interface Category {
  id: number
  name: string
  slug: string
  description: string | null
  color: string
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
}
export interface CategoryListQuery {
  status?: 'active' | 'inactive'
}
export interface CreateCategoryPayload {
  name: string
  description?: string | null
  color?: string
  is_active?: boolean
  sort_order?: number
}
export type UpdateCategoryPayload = Partial<
  Pick<Category, 'name' | 'description' | 'color' | 'is_active' | 'sort_order'>
>
export async function listCategories(
  query: CategoryListQuery = {},
): Promise<Category[]> {
  const { data } = await client.get<{ data: Category[] }>('/categories', {
    params: query,
  })
  return data.data
}
export async function createCategory(
  payload: CreateCategoryPayload,
): Promise<Category> {
  const { data } = await client.post<{ data: Category }>('/categories', payload)
  return data.data
}
export async function updateCategory(
  id: number,
  payload: UpdateCategoryPayload,
): Promise<Category> {
  const { data } = await client.patch<{ data: Category }>(
    `/categories/${id}`,
    payload,
  )
  return data.data
}
export async function deleteCategory(
  id: number,
  reassignTo?: number,
): Promise<void> {
  await client.delete(`/categories/${id}`, {
    data: reassignTo === undefined ? undefined : { reassign_to: reassignTo },
  })
}
export function deleteBlockedBy(error: unknown): CategoryDeleteBlocked | null {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return null
  const payload = error.response.data as
    Partial<CategoryDeleteBlocked> | undefined
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
