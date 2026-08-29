import client from './client'
export type StatusBucket = 'open' | 'pending' | 'done'
export interface Status {
  id: number
  name: string
  slug: string
  bucket: StatusBucket
  color: string
  is_default: boolean
  is_terminal: boolean
  sort_order: number
}
// The SPA only needs to recognise Resolved and Reopened (to reveal their
// required text fields); `closed` and `new` stay backend-only concerns.
export const RESOLVED_SLUG = 'resolved'
export const REOPENED_SLUG = 'reopened'

export async function listStatuses(): Promise<Status[]> {
  const { data } = await client.get<{ data: Status[] }>('/statuses')
  return data.data
}
